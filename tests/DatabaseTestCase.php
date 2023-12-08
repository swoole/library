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
    protected function getMysqliPool(): MysqliPool
    {
        $config = (new MysqliConfig())
            ->withHost(MYSQL_SERVER_HOST)
            ->withPort(MYSQL_SERVER_PORT)
            ->withDbName(MYSQL_SERVER_DB)
            ->withCharset('utf8mb4')
            ->withUsername(MYSQL_SERVER_USER)
            ->withPassword(MYSQL_SERVER_PWD)
        ;

        return new MysqliPool($config);
    }

    protected function getPdoPool(): PDOPool
    {
        $config = (new PDOConfig())
            ->withHost(MYSQL_SERVER_HOST)
            ->withPort(MYSQL_SERVER_PORT)
            ->withDbName(MYSQL_SERVER_DB)
            ->withCharset('utf8mb4')
            ->withUsername(MYSQL_SERVER_USER)
            ->withPassword(MYSQL_SERVER_PWD)
        ;

        return new PDOPool($config);
    }

    protected function getRedisPool(): RedisPool
    {
        $config = (new RedisConfig())->withHost(REDIS_SERVER_HOST)->withPort(REDIS_SERVER_PORT);

        return new RedisPool($config);
    }
}
