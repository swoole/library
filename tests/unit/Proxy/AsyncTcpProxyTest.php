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
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Swoole\Proxy\AsyncTcpProxy;
use Swoole\Proxy\TcpDownstream;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Proxy\AsyncTcpProxy
 */
class AsyncTcpProxyTest extends TestCase
{
    public function testBufferedBytesAreDeliveredAfterConnect(): void
    {
        self::coRun(function (): void {
            $backend = $this->echoServer();
            $down    = new RecordingDownstream();
            $proxy   = new AsyncTcpProxy($down, '127.0.0.1', $backend->port, 1.0);
            Coroutine::create(static fn () => $backend->start());
            try {
                $proxy->receive(4, 'ping');
                $proxy->receive(4, 'pong');
                $this->assertTrue($this->waitUntil(static fn () => ($down->data[4] ?? '') === 'pingpong'));
                $proxy->close(4);
            } finally {
                $backend->shutdown();
            }
        });
    }

    public function testAnUnreachableBackendClosesWithoutWriting(): void
    {
        self::coRun(function (): void {
            $down  = new RecordingDownstream();
            $proxy = new AsyncTcpProxy($down, '127.0.0.1', 1, 0.3);
            $proxy->receive(2, 'ping');
            $this->assertTrue($this->waitUntil(static fn () => ($down->closed[2] ?? false) === true));
            $this->assertArrayNotHasKey(2, $down->data);
            $proxy->close(2);
        });
    }

    public function testClosingOneHandshakeDoesNotAffectTheNextConnectionOnThatFd(): void
    {
        self::coRun(function (): void {
            $backend = $this->echoServer();
            $down    = new RecordingDownstream();
            $proxy   = new AsyncTcpProxy($down, '127.0.0.1', $backend->port, 1.0);
            Coroutine::create(static fn () => $backend->start());
            try {
                $proxy->receive(5, 'old');
                $this->assertTrue($this->waitUntil(static fn () => ($down->data[5] ?? '') === 'old'));
                $proxy->close(5);
                $down->data[5]   = '';
                $down->closed[5] = false;
                $proxy->receive(5, 'new');
                $this->assertTrue($this->waitUntil(static fn () => $down->data[5] === 'new'));
                $this->assertFalse($down->closed[5]);
                $proxy->close(5);
            } finally {
                $backend->shutdown();
            }
        });
    }

    public function testPendingBytesAreCapped(): void
    {
        self::coRun(function (): void {
            $down  = new RecordingDownstream();
            $proxy = new AsyncTcpProxy($down, '127.0.0.1', 1, 1.0, 4);
            $proxy->receive(8, 'hello');
            $this->assertTrue($down->closed[8] ?? false);
            $this->assertArrayNotHasKey(8, $down->data);
        });
    }

    private function echoServer(): Server
    {
        $server = new Server('127.0.0.1', 0);
        $server->handle(static function (Connection $connection): void {
            while (true) {
                $data = $connection->recv(1);
                if (!is_string($data) || $data === '') {
                    $connection->close();

                    return;
                }
                $connection->send($data);
            }
        });

        return $server;
    }

    private function waitUntil(\Closure $ready): bool
    {
        $deadline = microtime(true) + 1.5;
        while (microtime(true) < $deadline) {
            if ($ready()) {
                return true;
            }
            Coroutine::sleep(0.01);
        }

        return $ready();
    }
}

final class RecordingDownstream implements TcpDownstream
{
    /** @var array<int, string> */
    public array $data = [];

    /** @var array<int, bool> */
    public array $closed = [];

    public function send(int $fd, string $data): bool
    {
        $this->data[$fd] = ($this->data[$fd] ?? '') . $data;

        return true;
    }

    public function close(int $fd): void
    {
        $this->closed[$fd] = true;
    }

    public function pause(int $fd): void
    {
    }

    public function resume(int $fd): void
    {
    }
}
