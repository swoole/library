<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\NameResolver;

use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class TransportTest extends TestCase
{
    public function testHttpTransportPreservesHostAndEncodesServiceName(): void
    {
        self::coRun(function () {
            $listener = new Socket(AF_INET, SOCK_STREAM, 0);
            self::assertTrue($listener->bind('127.0.0.1', 0));
            self::assertTrue($listener->listen());
            $port     = $listener->getsockname()['port'];
            $requests = [];
            Coroutine::create(function () use ($listener, &$requests) {
                $connection = $listener->accept(1);
                try {
                    $data = '';
                    while (!str_contains($data, "\r\n\r\n")) {
                        $data .= $connection->recv(4096, 1);
                    }
                    $requests[] = $data;
                    $body       = '[{"Service":{"Address":"127.0.0.2","Port":8080},"Checks":[]}]';
                    $connection->sendAll("HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
                } finally {
                    $connection->close();
                }
            });
            try {
                $resolver = (new Consul('http://localhost:' . $port . '/gateway'))->withToken('secret');
                self::assertSame('127.0.0.2', $resolver->lookup('a/b?c')['host']);
                self::assertStringContainsString('GET /gateway/v1/health/service/swoole_service_a%2Fb%3Fc?passing=true HTTP/1.1', $requests[0]);
                self::assertStringContainsString('Host: localhost:' . $port, $requests[0]);
                self::assertStringContainsString('X-Consul-Token: secret', $requests[0]);
            } finally {
                $listener->close();
            }
        });
    }

    public function testHttpReadTimeoutIsReportedAsBackendFailure(): void
    {
        self::coRun(function () {
            $listener = new Socket(AF_INET, SOCK_STREAM, 0);
            $listener->bind('127.0.0.1', 0);
            $listener->listen();
            $port = $listener->getsockname()['port'];
            Coroutine::create(function () use ($listener) {
                $connection = $listener->accept(1);
                $connection->recv(4096, 1);
                Coroutine::sleep(0.1);
                $connection->close();
            });
            try {
                (new Consul('http://127.0.0.1:' . $port))->withTimeout(0.02)->withFilter(fn () => true)->lookup('test');
                self::fail('The registry read should time out');
            } catch (Exception $e) {
                self::assertStringContainsString('Registry request failed', $e->getMessage());
            } finally {
                $listener->close();
            }
        });
    }
}
