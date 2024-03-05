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
     * @return array<array{0: callable, 1: class-string, 2: class-string<ObjectProxy>|null}>
     */
    public static function dataDatabaseObjectProxy(): array
    {
        return [
            [[self::class, 'getMysqliPool'], \mysqli::class, MysqliProxy::class],
            [[self::class, 'getPdoMysqlPool'], \PDO::class, PDOProxy::class],
            [[self::class, 'getPdoOraclePool'], \PDO::class, PDOProxy::class],
            [[self::class, 'getPdoPgsqlPool'], \PDO::class, PDOProxy::class],
            [[self::class, 'getPdoSqlitePool'], \PDO::class, PDOProxy::class],
            [[self::class, 'getRedisPool'], \Redis::class],
        ];
    }

    /**
     * @param class-string $expectedObjectClass
     * @param class-string<ObjectProxy>|null $expectedProxyClass
     * @dataProvider dataDatabaseObjectProxy
     * @covers \Swoole\Database\ObjectProxy::__clone()
     */
    public function testDatabaseObjectProxy(callable $callback, string $expectedObjectClass, ?string $expectedProxyClass = null): void
    {
        Coroutine\run(function () use ($callback, $expectedObjectClass, $expectedProxyClass): void {
            $pool = $callback();
            self::assertInstanceOf(ConnectionPool::class, $pool);
            /** @var ConnectionPool $pool */
            $conn = $pool->get();

            if (is_null($expectedProxyClass)) { // Proxy class not in use?
                self::assertInstanceOf($expectedObjectClass, $conn);
            } else {
                self::assertInstanceOf($expectedProxyClass, $conn);
                self::assertInstanceOf($expectedObjectClass, $conn->__getObject());
            }
        });
    }

    /**
     * @return array<array<callable>>
     */
    public static function dataUncloneableDatabaseProxyObject(): array
    {
        return [
            [[self::class, 'getMysqliPool']],
            [[self::class, 'getPdoMysqlPool']],
            [[self::class, 'getPdoOraclePool']],
            [[self::class, 'getPdoPgsqlPool']],
            [[self::class, 'getPdoSqlitePool']],
        ];
    }

    /**
     * @depends testDatabaseObjectProxy
     * @dataProvider dataUncloneableDatabaseProxyObject
     * @covers \Swoole\Database\ObjectProxy::__clone()
     */
    public function testUncloneableDatabaseProxyObject(callable $callback): void
    {
        Coroutine\run(function () use ($callback): void {
            /** @var ConnectionPool $pool */
            $pool = $callback();
            try {
                clone $pool->get();
            } catch (\Error $e) {
                if ($e->getMessage() != 'Trying to clone an uncloneable database proxy object') {
                    throw $e;
                }
            }
        });
    }
}
