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

use Swoole\Database\MysqliProxy;
use Swoole\Database\ObjectProxy;
use Swoole\Database\PDOProxy;
use Swoole\Tests\DatabaseTestCase;

/**
 * Class ObjectProxyTest
 *
 * @internal
 * @coversNothing
 */
class ObjectProxyTest extends DatabaseTestCase
{
    /**
     * @return array<array<ObjectProxy>>
     */
    public static function dateClone(): array
    {
        return [
            [[self::class, 'getMysqliPool'], MysqliProxy::class],
            [[self::class, 'getPdoMysqlPool'], PDOProxy::class],
            [[self::class, 'getPdoOraclePool'], PDOProxy::class],
            [[self::class, 'getPdoPgsqlPool'], PDOProxy::class],
            [[self::class, 'getPdoSqlitePool'], PDOProxy::class],
        ];
    }

    /**
     * @param class-string<ObjectProxy> $class
     * @dataProvider dateClone
     * @covers \Swoole\Database\ObjectProxy::__clone()
     */
    public function testClone(callable $callback, string $class): void
    {
        Coroutine\run(function () use ($callback, $class): void {
            $pool = $callback();
            self::assertInstanceOf(ConnectionPool::class, $pool);
            /** @var ConnectionPool $pool */
            $proxy = $pool->get();
            self::assertInstanceOf($class, $proxy);

            try {
                clone $proxy;
            } catch (\Error $e) {
                if ($e->getMessage() != 'Trying to clone an uncloneable database proxy object') {
                    throw $e;
                }
            }
        });
    }
}
