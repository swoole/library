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
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Swoole\FastCGI;
use Swoole\FastCGI\HttpRequest;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Coroutine\FastCGI\Client
 */
class ClientTest extends TestCase
{
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
                self::assertNull((fn () => $this->socket ?? null)->call($client), 'The connection is closed.');
            } finally {
                $server->shutdown();
            }
        });
    }
}
