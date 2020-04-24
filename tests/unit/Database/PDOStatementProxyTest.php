<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Database;

use PDO;
use Swoole\Coroutine;
use Swoole\Tests\DatabaseTestCase;

/**
 * Class PDOStatementProxyTest
 *
 * @internal
 * @coversNothing
 */
class PDOStatementProxyTest extends DatabaseTestCase
{
    /**
     * @covers \Swoole\Database\PDOStatementProxy::__call()
     */
    public function testRun()
    {
        Coroutine\run(function () {
            self::assertFalse(
                $this->getPdoPool()->get()->query("SHOW TABLES like 'NON_EXISTING_TABLE_NAME'")->fetch(PDO::FETCH_ASSOC),
                'FALSE is returned if no results found.'
            );
        });
    }
}
