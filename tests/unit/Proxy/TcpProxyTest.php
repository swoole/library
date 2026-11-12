<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Proxy;

use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Swoole\Proxy\TcpProxy;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Proxy\Relay
 * @covers \Swoole\Proxy\TcpProxy
 */
class TcpProxyTest extends TestCase
{
    public function testBytesGoBothWays(): void
    {
        self::coRun(function (): void {
            $backend = new Server('127.0.0.1', 0);
            $backend->handle(static function (Connection $connection): void {
                $connection->send('hello');
                $data = $connection->recv(1);
                if (is_string($data) && $data !== '') {
                    $connection->send($data);
                }
                $connection->close();
            });
            $proxyServer = new Server('127.0.0.1', 0);
            $proxy       = new TcpProxy('127.0.0.1', $backend->port, 1.0, 1.0);
            $proxy->attach($proxyServer);
            Coroutine::create(static fn () => $backend->start());
            Coroutine::create(static fn () => $proxyServer->start());
            try {
                $client = new Client(SWOOLE_SOCK_TCP);
                $this->assertTrue($client->connect('127.0.0.1', $proxyServer->port, 1));
                $this->assertSame('hello', $this->readExactly($client, 5, 1));
                $this->assertSame(4, $client->send('ping'));
                $this->assertSame('ping', $this->readExactly($client, 4, 1));
                $client->close();
            } finally {
                $proxyServer->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testHalfCloseStillDeliversTheReply(): void
    {
        self::coRun(function (): void {
            $backend = new Server('127.0.0.1', 0);
            $backend->handle(static function (Connection $connection): void {
                $buffer = '';
                while (true) {
                    $data = $connection->recv(1);
                    if (!is_string($data) || $data === '') {
                        break;
                    }
                    $buffer .= $data;
                }
                $connection->send('got:' . $buffer);
                $connection->close();
            });
            $proxyServer = new Server('127.0.0.1', 0);
            (new TcpProxy('127.0.0.1', $backend->port, 1.0, 1.0))->attach($proxyServer);
            Coroutine::create(static fn () => $backend->start());
            Coroutine::create(static fn () => $proxyServer->start());
            try {
                $client = new Client(SWOOLE_SOCK_TCP);
                $this->assertTrue($client->connect('127.0.0.1', $proxyServer->port, 1));
                $this->assertSame(3, $client->send('abc'));
                $socket = $client->exportSocket();
                $this->assertNotNull($socket);
                $this->assertTrue($socket->shutdown(\Swoole\Client::SHUT_WR));
                $this->assertSame('got:abc', $this->readExactly($client, 7, 1));
                $client->close();
            } finally {
                $proxyServer->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testAnUnreachableBackendClosesTheClient(): void
    {
        self::coRun(function (): void {
            $proxyServer = new Server('127.0.0.1', 0);
            (new TcpProxy('127.0.0.1', 1, 1.0, 0.3))->attach($proxyServer);
            Coroutine::create(static fn () => $proxyServer->start());
            try {
                $client = new Client(SWOOLE_SOCK_TCP);
                $this->assertTrue($client->connect('127.0.0.1', $proxyServer->port, 1));
                $this->assertSame(4, $client->send('ping'));
                $data = $client->recv(1);
                $this->assertTrue($data === '' || $data === false);
                $client->close();
            } finally {
                $proxyServer->shutdown();
            }
        });
    }

    public function testALargePayloadIsCopiedWhole(): void
    {
        self::coRun(function (): void {
            $payload = str_repeat('abc', 100000);
            $backend = new Server('127.0.0.1', 0);
            $backend->handle(static function (Connection $connection) use ($payload): void {
                $buffer = '';
                while (strlen($buffer) < strlen($payload)) {
                    $data = $connection->recv(1);
                    if (!is_string($data) || $data === '') {
                        break;
                    }
                    $buffer .= $data;
                }
                $connection->send($buffer);
                $connection->close();
            });
            $proxyServer = new Server('127.0.0.1', 0);
            (new TcpProxy('127.0.0.1', $backend->port, 2.0, 1.0))->attach($proxyServer);
            Coroutine::create(static fn () => $backend->start());
            Coroutine::create(static fn () => $proxyServer->start());
            try {
                $client = new Client(SWOOLE_SOCK_TCP);
                $this->assertTrue($client->connect('127.0.0.1', $proxyServer->port, 1));
                $this->assertTrue($this->sendAll($client, $payload));
                $this->assertSame($payload, $this->readExactly($client, strlen($payload), 2));
                $client->close();
            } finally {
                $proxyServer->shutdown();
                $backend->shutdown();
            }
        });
    }

    private function readExactly(Client $client, int $length, float $timeout): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $data = $client->recv($timeout);
            if (!is_string($data) || $data === '') {
                break;
            }
            $buffer .= $data;
        }

        return $buffer;
    }

    private function sendAll(Client $client, string $data): bool
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $sent = $client->send(substr($data, $offset));
            if (!is_int($sent) || $sent <= 0) {
                return false;
            }
            $offset += $sent;
        }

        return true;
    }
}
