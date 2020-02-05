<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Library\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;

/**
 * Class PDOStatementProxyTest
 *
 * @internal
 * @coversNothing
 */
class PDOStatementProxyTest extends TestCase
{
    /**
     * @covers \PDOStatementProxy::__call()
     */
    public function testRun()
    {
        Coroutine\run(function () {
            $config = (new PDOConfig())
                ->withHost(MYSQL_SERVER_HOST)
                ->withPort(MYSQL_SERVER_PORT)
                ->withDbName(MYSQL_SERVER_DB)
                ->withCharset('utf8mb4')
                ->withUsername(MYSQL_SERVER_USER)
                ->withPassword(MYSQL_SERVER_PWD);

            $db = (new PDOPool($config))->get();
            self::assertFalse(
                $db->query("SHOW TABLES like 'NON_EXISTING_TABLE_NAME'")->fetch(PDO::FETCH_ASSOC),
                'FALSE is returned if no results found.'
            );
        });
    }
}
