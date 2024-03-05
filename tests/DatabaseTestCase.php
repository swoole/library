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

use PHPUnit\Framework\TestCase;
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
    protected static string $sqliteDatabaseFile;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::$sqliteDatabaseFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('swoole_pdo_pool_sqlite_test_', true);
        if (file_exists(static::$sqliteDatabaseFile)) {
            unlink(static::$sqliteDatabaseFile);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (file_exists(static::$sqliteDatabaseFile)) {
            unlink(static::$sqliteDatabaseFile);
        }
        parent::tearDownAfterClass();
    }

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

    protected static function getPdoSqlitePool(int $size = ConnectionPool::DEFAULT_SIZE): PDOPool
    {
        $config = (new PDOConfig())->withDriver('sqlite')->withDbname(static::$sqliteDatabaseFile);

        return new PDOPool($config, $size);
    }

    protected static function getRedisPool(int $size = ConnectionPool::DEFAULT_SIZE): RedisPool
    {
        $config = (new RedisConfig())->withHost(REDIS_SERVER_HOST)->withPort(REDIS_SERVER_PORT);

        return new RedisPool($config, $size);
    }
}
