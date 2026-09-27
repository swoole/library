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
}
