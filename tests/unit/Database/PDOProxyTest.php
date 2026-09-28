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

use Swoole\Tests\DatabaseTestCase;

/**
 * @internal
 * @covers \Swoole\Database\PDOProxy
 */
class PDOProxyTest extends DatabaseTestCase
{
    /**
     * The proxy turns the exception error mode on, but the application is free to pick another one, and
     * PDO::query() and PDO::prepare() return false in those instead of a statement.
     */
    public function testFailedStatementInSilentErrorMode(): void
    {
        self::coRun(function () {
            $pdo = self::getPdoSqlitePool(1)->get();
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);

            self::assertFalse($pdo->query('SELECT * FROM no_such_table'));
            self::assertFalse($pdo->prepare('SELECT * FROM no_such_table'));
            self::assertSame('HY000', $pdo->errorCode());

            self::assertInstanceOf(PDOStatementProxy::class, $pdo->query('SELECT 1'));
        });
    }

    /**
     * inTransaction() follows beginTransaction(), commit() and rollBack(), and a connection handed out by the pool
     * starts anew.
     */
    public function testInTransaction(): void
    {
        self::coRun(function () {
            $pool = self::getPdoSqlitePool(1);
            $pdo  = $pool->get();
            self::assertFalse($pdo->inTransaction());

            $pdo->beginTransaction();
            self::assertTrue($pdo->inTransaction());
            $pdo->commit();
            self::assertFalse($pdo->inTransaction());

            $pdo->beginTransaction();
            self::assertTrue($pdo->inTransaction());
            $pdo->rollBack();
            self::assertFalse($pdo->inTransaction());

            $pdo->beginTransaction();
            $pool->put($pdo);
            $pdo = $pool->get();
            self::assertFalse($pdo->inTransaction());
            self::assertFalse($pdo->__getObject()->inTransaction(), 'The pool rolled the transaction back.');
        });
    }
}
