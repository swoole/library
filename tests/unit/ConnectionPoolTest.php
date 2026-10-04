<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole;

use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\ConnectionPool
 */
class ConnectionPoolTest extends TestCase
{
    public function testCloseTwice(): void
    {
        self::coRun(function () {
            $pool = new ConnectionPool(fn () => new \stdClass(), 2);
            $pool->fill();

            $pool->close();
            $pool->close();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Pool has been closed');
            $pool->get();
        });
    }

    public function testFillAfterClose(): void
    {
        self::coRun(function () {
            $made = 0;
            $pool = new ConnectionPool(function () use (&$made) {
                $made++;
                return new \stdClass();
            }, 2);
            $pool->fill();
            self::assertSame(2, $made);

            $pool->close();
            $pool->fill();
            self::assertSame(2, $made, 'A closed pool makes no connection: there is nowhere to keep it.');
        });
    }

    public function testWithConnectionReturnsTheResultAndPutsTheConnectionBack(): void
    {
        self::coRun(function () {
            $pool = new ConnectionPool(fn () => new \stdClass(), 1);

            $used = null;
            self::assertSame('result', $pool->withConnection(function (\stdClass $connection) use (&$used): string {
                $used = $connection;
                return 'result';
            }));
            self::assertNull($pool->withConnection(function (): void {}), 'A callback without a result gives null.');
            self::assertSame($used, $pool->get(0.1), 'The connection is back in the pool.');
        });
    }

    /**
     * The connection is put back as it is when the callback throws, not replaced: the exception may well have come
     * from a connection that is fine, such as a constraint violation.
     */
    public function testWithConnectionPutsTheConnectionBackWhenTheCallbackThrows(): void
    {
        self::coRun(function () {
            $made = 0;
            $pool = new ConnectionPool(function () use (&$made) {
                $made++;
                return new \stdClass();
            }, 1);

            $exception = new \LogicException('from the callback');
            $used      = null;
            try {
                $pool->withConnection(function (\stdClass $connection) use ($exception, &$used): void {
                    $used = $connection;
                    throw $exception;
                });
                self::fail('The exception of the callback is not caught.');
            } catch (\LogicException $e) {
                self::assertSame($exception, $e);
            }
            self::assertSame($used, $pool->get(0.1));
            self::assertSame(1, $made);
        });
    }

    /**
     * The defect the method is for: a connection taken with get() and not put back, because an exception went past
     * the put(), is lost to the pool, and once that has happened as many times as the pool is large, there is none.
     */
    public function testWithConnectionLosesNoConnectionToExceptions(): void
    {
        self::coRun(function () {
            $pool = new ConnectionPool(fn () => new \stdClass(), 2);
            for ($i = 0; $i < 3; $i++) {
                try {
                    $pool->withConnection(function (): void {
                        throw new \RuntimeException('failed');
                    }, 0.1);
                } catch (\RuntimeException $e) {
                    self::assertSame('failed', $e->getMessage());
                }
            }
            self::assertTrue($pool->withConnection(fn (): bool => true, 0.1));
        });
    }

    public function testWithConnectionWhenNoConnectionIsAvailableInTime(): void
    {
        self::coRun(function () {
            $pool = new ConnectionPool(fn () => new \stdClass(), 1);
            $held = $pool->get();

            $called = false;
            try {
                $pool->withConnection(function () use (&$called): void {
                    $called = true;
                }, 0.1);
                self::fail('The only connection is held.');
            } catch (\RuntimeException $e) {
                self::assertSame('No connection is available from the pool within 0.1 seconds', $e->getMessage());
            }
            self::assertFalse($called, 'The callback is not called without a connection.');

            $pool->put($held);
        });
    }

    /**
     * A call on the same pool from inside the callback needs a second connection. With a pool of one it would wait
     * forever; with a timeout it fails, and the outer call still puts its connection back.
     */
    public function testWithConnectionNestedOnAPoolOfOne(): void
    {
        self::coRun(function () {
            $pool = new ConnectionPool(fn () => new \stdClass(), 1);
            try {
                $pool->withConnection(fn () => $pool->withConnection(fn () => null, 0.1));
                self::fail('There is no second connection.');
            } catch (\RuntimeException $e) {
                self::assertStringStartsWith('No connection is available', $e->getMessage());
            }
            self::assertTrue($pool->withConnection(fn (): bool => true, 0.1), 'The pool is intact.');
        });
    }

    public function testWithConnectionOnAClosedPool(): void
    {
        self::coRun(function () {
            $pool = new ConnectionPool(fn () => new \stdClass(), 1);
            $pool->close();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Pool has been closed');
            $pool->withConnection(fn () => null);
        });
    }

    /**
     * A coroutine that waits for a connection when the pool is closed is told so, and not that it waited too long.
     */
    public function testWithConnectionWhenThePoolIsClosedWhileWaiting(): void
    {
        self::coRun(function () {
            $pool = new ConnectionPool(fn () => new \stdClass(), 1);
            $held = $pool->get();

            $message = null;
            Coroutine::create(function () use ($pool, &$message): void {
                try {
                    $pool->withConnection(fn () => null);
                } catch (\RuntimeException $e) {
                    $message = $e->getMessage();
                }
            });
            $pool->close();
            Coroutine::sleep(0.01);

            self::assertSame('Pool has been closed', $message);
            unset($held);
        });
    }

    public function testWithConnectionWhenTheWaitIsCanceled(): void
    {
        self::coRun(function () {
            $pool = new ConnectionPool(fn () => new \stdClass(), 1);
            $held = $pool->get();

            $message = null;
            $cid     = Coroutine::create(function () use ($pool, &$message): void {
                try {
                    $pool->withConnection(fn () => null);
                } catch (\RuntimeException $e) {
                    $message = $e->getMessage();
                }
            });
            Coroutine::cancel($cid);
            Coroutine::sleep(0.01);

            self::assertSame('Canceled while waiting for a connection from the pool', $message);
            $pool->put($held);
            self::assertTrue($pool->withConnection(fn (): bool => true, 0.1), 'The pool is intact.');
        });
    }

    public function testWithConnectionWhenThePoolIsClosedInsideTheCallback(): void
    {
        self::coRun(function () {
            $pool = new ConnectionPool(fn () => new \stdClass(), 1);
            self::assertSame('result', $pool->withConnection(function () use ($pool): string {
                $pool->close();
                return 'result';
            }));
        });
    }
}
