<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine\FastCGI;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Swoole\FastCGI;
use Swoole\FastCGI\HttpRequest;
use Swoole\FastCGI\Record\EndRequest;
use Swoole\FastCGI\Record\Stdout;
use Swoole\FastCGI\Request;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Coroutine\FastCGI\Client
 */
class ClientTest extends TestCase
{
    public function testLargeResponseWithInterleavedStderr(): void
    {
        self::coRun(function (): void {
            $chunk  = str_repeat("a\0\xffb", 8192);
            $server = new Server('127.0.0.1', 0);
            $server->handle(static function (Connection $connection) use ($chunk): void {
                $connection->recv();
                $connection->send((new Stdout("Content-Type: application/octet-stream\n"))->toString());
                $connection->send((new Stdout("\n"))->toString());
                for ($i = 0; $i < 64; $i++) {
                    $connection->send((new Stdout($chunk))->toString());
                    $connection->send((new FastCGI\Record\Stderr('diagnostic'))->toString());
                }
                $connection->send(new Stdout('') . new EndRequest(FastCGI::REQUEST_COMPLETE, 23));
                $connection->close();
            });
            Coroutine::create(static fn () => $server->start());
            try {
                $response = (new Client('127.0.0.1', $server->port))->execute(new HttpRequest(), 1);
                self::assertSame(200, $response->getStatusCode());
                self::assertSame(str_repeat($chunk, 64), $response->getBody());
                self::assertSame(str_repeat('diagnostic', 64), $response->getError());
                self::assertSame(23, $response->getAppStatus());
            } finally {
                $server->shutdown();
            }
        });
    }

    public function testCallPreservesAZeroQueryString(): void
    {
        self::coRun(function (): void {
            $body   = Client::call('tcp://php-fpm:9000', DOCUMENT_ROOT . '/fastcgi/inspect.php?0', '', 1);
            $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('/inspect.php?0', $result['request_uri']);
            self::assertSame('0', $result['query']);
        });
    }

    /** @dataProvider invalidResponses */
    public function testInvalidResponsesCloseKeptConnections(string $frames): void
    {
        self::coRun(function () use ($frames): void {
            $server = new Server('127.0.0.1', 0);
            $server->handle(static function (Connection $connection) use ($frames): void {
                $connection->recv();
                $connection->send($frames);
                $connection->recv(1);
                $connection->close();
            });
            Coroutine::create(static fn () => $server->start());
            $client = new Client('127.0.0.1', $server->port);
            try {
                $client->execute((new HttpRequest())->withKeepConn(true), 1);
                self::fail('An invalid FastCGI response must not succeed.');
            } catch (\DomainException $e) {
                self::assertFalse($client->isConnected());
            } finally {
                $server->shutdown();
            }
        });
    }

    public static function invalidResponses(): array
    {
        $stdout = (string) new Stdout("Content-Type: text/plain\r\n\r\nhello");
        $end    = (string) new EndRequest();
        return [
            'wrong request ID'    => [substr_replace($stdout, "\0\2", 2, 2) . substr_replace($end, "\0\2", 2, 2)],
            'unsupported version' => [substr_replace($stdout, "\x09", 0, 1) . $end],
            'empty END_REQUEST'   => [$stdout . pack('CCnnCC', 1, FastCGI::END_REQUEST, 1, 0, 0, 0)],
            'short END_REQUEST'   => [$stdout . pack('CCnnCC', 1, FastCGI::END_REQUEST, 1, 7, 0, 0) . str_repeat("\0", 7)],
            'long END_REQUEST'    => [$stdout . pack('CCnnCC', 1, FastCGI::END_REQUEST, 1, 9, 0, 0) . str_repeat("\0", 9)],
            'unexpected STDIN'    => [pack('CCnnCC', 1, FastCGI::STDIN, 1, 0, 0, 0) . $end],
            'overloaded'          => [$stdout . new EndRequest(FastCGI::OVERLOADED)],
        ];
    }

    public function testEmptyBodyTerminatesStdin(): void
    {
        self::coRun(function (): void {
            $terminated = false;
            $server     = new Server('127.0.0.1', 0);
            $server->handle(static function (Connection $connection) use (&$terminated): void {
                $connection->exportSocket()->setProtocol(['open_fastcgi_protocol' => true]);
                while (is_string($frame = $connection->recv(1)) && $frame !== '') {
                    $header = unpack('Cversion/Ctype/nid/nlength', $frame);
                    if ($header['type'] === FastCGI::STDIN && $header['length'] === 0) {
                        $terminated = true;
                        $connection->send(new Stdout("Content-Type: text/plain\r\n\r\nhello") . new EndRequest());
                        break;
                    }
                }
                $connection->close();
            });
            Coroutine::create(static fn () => $server->start());
            try {
                $response = (new Client('127.0.0.1', $server->port))->execute(new HttpRequest(), 1);
                self::assertTrue($terminated);
                self::assertSame('hello', $response->getBody());
            } finally {
                $server->shutdown();
            }
        });
    }

    public function testSendUsesTheExecuteTimeout(): void
    {
        self::coRun(function (): void {
            $release = new Channel(1);
            $server  = new Server('127.0.0.1', 0);
            $server->handle(static function (Connection $connection) use ($release): void {
                $connection->exportSocket()->setOption(SOL_SOCKET, SO_RCVBUF, 4096);
                // No reads: the request cannot fit in the kernel buffers. Bound the wait even on failure.
                $release->pop(2);
                $connection->close();
            });
            Coroutine::create(static fn () => $server->start());
            $client = new Client('127.0.0.1', $server->port);
            try {
                $client->execute((new HttpRequest())->withBody(str_repeat('x', 16 * 1024 * 1024)), 0.05);
                self::fail('Sending to a peer that does not read must time out.');
            } catch (Client\Exception $e) {
                self::assertSame(SOCKET_ETIMEDOUT, $e->getCode());
                self::assertFalse($client->isConnected());
            } finally {
                $release->push(true);
                $server->shutdown();
            }
        });
    }

    public function testOversizedParametersAreRejectedBeforeConnecting(): void
    {
        // An invalid request should report its encoding error, not a connection error at this address.
        $client = new Client('127.0.0.1', 0);
        try {
            $client->execute((new Request())->withParam('large', str_repeat('x', 65536)), 0.1);
            self::fail('Oversized PARAMS must be rejected.');
        } catch (\LengthException $e) {
            self::assertFalse($client->isConnected());
        }
    }

    public function testIpv6Url(): void
    {
        self::assertSame(['::1', 9000], Client::parseUrl('tcp://[::1]:9000'));
        self::assertSame(['2001:db8::1', 9000], Client::parseUrl('tcp://[2001:db8::1]:9000'));
        self::assertSame(['127.0.0.1', 9000], Client::parseUrl('tcp://127.0.0.1:9000'));
    }

    /**
     * A response that cannot be parsed leaves the rest of it unread on the connection. The connection is closed,
     * even when the request asked to keep it, so that the next request does not read that rest as its response.
     */
    public function testConnectionIsClosedWhenTheResponseCannotBeParsed(): void
    {
        self::coRun(function (): void {
            $server = new Server('127.0.0.1', 0);
            $server->handle(static function (Connection $connection): void {
                $connection->recv();
                // A record of a type FastCGI does not have.
                $connection->send(pack('CCnnCC', FastCGI::VERSION_1, 99, 1, 0, 0, 0));
                // Until the client closes the connection, or for a second when it keeps it open.
                $connection->recv(1);
                $connection->close();
            });
            Coroutine::create(static fn () => $server->start());

            $client = new Client('127.0.0.1', $server->port);
            try {
                $client->execute((new HttpRequest())->withKeepConn(true), 1);
                self::fail('The response cannot be parsed.');
            } catch (\DomainException $e) {
                self::assertSame('Invalid FastCGI record type 99 received', $e->getMessage());
                self::assertFalse($client->isConnected());
            } finally {
                $server->shutdown();
            }
        });
    }
}
