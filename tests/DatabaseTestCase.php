<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests;

use Swoole\ConnectionPool;
use Swoole\Database\MysqliConfig;
use Swoole\Database\MysqliPool;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;

/**
 * Class DatabaseTestCase
 *
 * @internal
 * @coversNothing
 */
class DatabaseTestCase extends TestCase
{
    protected static function getMysqliPool(int $size = ConnectionPool::DEFAULT_SIZE): MysqliPool
    {
        $config = (new MysqliConfig())
            ->withHost(MYSQL_SERVER_HOST)
            ->withPort(MYSQL_SERVER_PORT)
            ->withDbName(MYSQL_SERVER_DB)
            ->withCharset('utf8mb4')
            ->withUsername(MYSQL_SERVER_USER)
            ->withPassword(MYSQL_SERVER_PWD)
        ;

        return new MysqliPool($config, $size);
    }

    protected static function getPdoMysqlPool(int $size = ConnectionPool::DEFAULT_SIZE): PDOPool
    {
        $config = (new PDOConfig())
            ->withHost(MYSQL_SERVER_HOST)
            ->withPort(MYSQL_SERVER_PORT)
            ->withDbName(MYSQL_SERVER_DB)
            ->withCharset('utf8mb4')
            ->withUsername(MYSQL_SERVER_USER)
            ->withPassword(MYSQL_SERVER_PWD)
        ;

        return new PDOPool($config, $size);
    }

    protected static function getPdoPgsqlPool(int $size = ConnectionPool::DEFAULT_SIZE): PDOPool
    {
        $config = (new PDOConfig())
            ->withDriver('pgsql')
            ->withHost(PGSQL_SERVER_HOST)
            ->withPort(PGSQL_SERVER_PORT)
            ->withDbName(PGSQL_SERVER_DB)
            ->withUsername(PGSQL_SERVER_USER)
            ->withPassword(PGSQL_SERVER_PWD)
        ;

        return new PDOPool($config, $size);
    }

    protected static function getPdoOraclePool(int $size = ConnectionPool::DEFAULT_SIZE): PDOPool
    {
        $config = (new PDOConfig())
            ->withDriver('oci')
            ->withHost(ORACLE_SERVER_HOST)
            ->withPort(ORACLE_SERVER_PORT)
            ->withDbName(ORACLE_SERVER_DB)
            ->withCharset('AL32UTF8')
            ->withUsername(ORACLE_SERVER_USER)
            ->withPassword(ORACLE_SERVER_PWD)
        ;

        return new PDOPool($config, $size);
    }

    /**
     * Every call gets a SQLite database file of its own, removed when the run ends.
     *
     * The file cannot be shared between tests, nor picked and deleted from setUpBeforeClass() and
     * tearDownAfterClass(): PHPUnit runs those two hooks outside the test coroutines, so under counit a
     * test returns to PHPUnit at its first yield and the file was deleted while the test using it was
     * still running, which SQLite reports as "General error: 10 disk I/O error". Removing it at shutdown
     * keeps the cleanup off the concurrent path altogether.
     */
    protected static function getPdoSqlitePool(int $size = ConnectionPool::DEFAULT_SIZE): PDOPool
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('swoole_pdo_pool_sqlite_test_', true);
        register_shutdown_function(static function () use ($file): void {
            if (file_exists($file)) {
                unlink($file);
            }
        });
        $config = (new PDOConfig())->withDriver('sqlite')->withDbname($file);

        return new PDOPool($config, $size);
    }

    protected static function getRedisPool(int $size = ConnectionPool::DEFAULT_SIZE): RedisPool
    {
        $config = (new RedisConfig())->withHost(REDIS_SERVER_HOST)->withPort(REDIS_SERVER_PORT);

        return new RedisPool($config, $size);
    }
}
