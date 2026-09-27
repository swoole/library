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
     * A transaction does not have to be started and ended through the methods of PDO.
     */
    public function testInTransactionFollowsTheConnection(): void
    {
        self::coRun(function () {
            $pdo = self::getPdoSqlitePool(1)->get();
            self::assertFalse($pdo->inTransaction());

            $pdo->exec('BEGIN');
            self::assertTrue($pdo->inTransaction(), 'A transaction started by a statement is a transaction.');
            $pdo->exec('ROLLBACK');
            self::assertFalse($pdo->inTransaction());

            $pdo->beginTransaction();
            self::assertTrue($pdo->inTransaction());
            $pdo->exec('COMMIT');
            self::assertFalse($pdo->inTransaction(), 'A transaction ended by a statement has ended.');
        });
    }

    /**
     * A connection put back with a transaction open is still inside that transaction when it is handed out again.
     */
    public function testInTransactionAfterGettingTheConnectionAgain(): void
    {
        self::coRun(function () {
            $pool = self::getPdoSqlitePool(1);
            $pdo  = $pool->get();
            $pdo->beginTransaction();
            $pool->put($pdo);

            $pdo = $pool->get();
            self::assertTrue($pdo->inTransaction());
            $pdo->rollBack();
            self::assertFalse($pdo->inTransaction());
        });
    }
}
