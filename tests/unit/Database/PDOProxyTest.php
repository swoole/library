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
            $pdo->__getObject()->rollBack();
        });
    }

    /**
     * query() and exec() run again on a fresh connection after a lost one, as execute() of a statement does. On
     * PostgreSQL they did not: PDO reports a connection it has lost as being inside a transaction.
     *
     * @dataProvider dataPools
     */
    public function testLostConnectionOnQueryAndExecIsRetriedOnTheServer(string $pool): void
    {
        self::coRun(function () use ($pool) {
            $pool = self::{$pool}(1);
            $pdo  = $pool->get();

            self::killPdoConnection($pdo);
            self::assertEquals(42, $pdo->query('SELECT 42')->fetchColumn());
            self::assertSame(1, $pdo->getRound());

            self::killPdoConnection($pdo);
            self::assertIsInt($pdo->exec('SELECT 43'));
            self::assertSame(2, $pdo->getRound());

            $pool->put($pdo);
            $pool->close();
        });
    }

    /**
     * Inside a transaction a lost connection is reported, as the transaction is lost with it, whether the
     * transaction was started with beginTransaction() or by hand. The code before the fix did the same; this test
     * keeps it that way.
     *
     * @dataProvider dataPools
     */
    public function testLostConnectionInsideATransactionIsReported(string $pool): void
    {
        self::coRun(function () use ($pool) {
            foreach (['beginTransaction()' => fn (PDOProxy $pdo) => $pdo->beginTransaction(), "exec('BEGIN')" => fn (PDOProxy $pdo) => $pdo->exec('BEGIN')] as $how => $begin) {
                // A pool of its own for each case: this pool puts the lost connection back as it is.
                $connections = self::{$pool}(1);
                $pdo         = $connections->get();
                $begin($pdo);
                self::killPdoConnection($pdo);
                try {
                    $pdo->query('SELECT 42');
                    self::fail("Inside a transaction started with {$how} the lost connection is reported.");
                } catch (\PDOException) {
                    self::assertSame(0, $pdo->getRound(), "No reconnect inside a transaction started with {$how}.");
                }
                $connections->close();
            }
        });
    }

    /**
     * A transaction ended behind the back of the proxy, here with exec('COMMIT'), leaves the counter of the proxy
     * at 1. The state PDO reports is what counts: the connection is outside a transaction, and reconnects.
     *
     * @dataProvider dataPools
     */
    public function testLostConnectionAfterATransactionEndedByHandIsRetried(string $pool): void
    {
        self::coRun(function () use ($pool) {
            $pool = self::{$pool}(1);
            $pdo  = $pool->get();

            $pdo->beginTransaction();
            $pdo->exec('COMMIT');
            self::killPdoConnection($pdo);
            self::assertEquals(42, $pdo->query('SELECT 42')->fetchColumn());
            self::assertSame(1, $pdo->getRound());

            $pool->put($pdo);
            $pool->close();
        });
    }

    /**
     * beginTransaction() on a connection lost while idle reconnects, and the transaction begins on the new one.
     *
     * @dataProvider dataPools
     */
    public function testBeginTransactionOnAConnectionLostWhileIdle(string $pool): void
    {
        self::coRun(function () use ($pool) {
            $pool = self::{$pool}(1);
            $pdo  = $pool->get();

            self::killPdoConnection($pdo);
            self::assertTrue($pdo->beginTransaction());
            self::assertSame(1, $pdo->getRound());
            self::assertTrue($pdo->inTransaction());
            self::assertTrue($pdo->__getObject()->inTransaction());
            self::assertTrue($pdo->rollBack());

            $pool->put($pdo);
            $pool->close();
        });
    }

    /**
     * A connection lost inside a transaction is reported, and the proxy reconnects on the next call, without a pool
     * to replace the connection. rollBack() of the transaction that died with the connection does not fail.
     *
     * @dataProvider dataPools
     */
    public function testConnectionLostInsideATransactionRecoversOnTheNextCall(string $pool): void
    {
        self::coRun(function () use ($pool) {
            $pool = self::{$pool}(1);
            $pdo  = $pool->get();

            $pdo->beginTransaction();
            self::killPdoConnection($pdo);
            try {
                $pdo->query('SELECT 42');
                self::fail('Inside a transaction the lost connection is reported.');
            } catch (\PDOException) {
                self::assertTrue($pdo->isLost());
                self::assertFalse($pdo->inTransaction(), 'The transaction went down with the connection.');
            }
            self::assertTrue($pdo->rollBack(), 'Nothing is left to roll back.');

            self::assertEquals(43, $pdo->query('SELECT 43')->fetchColumn());
            self::assertSame(1, $pdo->getRound());
            self::assertFalse($pdo->isLost());
            self::assertFalse($pdo->__getObject()->inTransaction());

            $pool->put($pdo);
            $pool->close();
        });
    }

    public static function dataPools(): array
    {
        return [
            'MySQL'      => ['getPdoMysqlPool'],
            'PostgreSQL' => ['getPdoPgsqlPool'],
        ];
    }

    /**
     * A reconnect that fails, as while the server restarts, is tried again by the next call. On PostgreSQL the proxy
     * stayed on the dead connection for good: PDO reports it as inside a transaction.
     *
     * @dataProvider dataDrivers
     */
    public function testFailedReconnectIsTriedAgain(string $driver): void
    {
        self::coRun(function () use ($driver) {
            $down = false;
            $pdo  = new PDOProxy(static function () use ($driver, &$down): \PDO {
                if ($down) {
                    throw new \PDOException('The server is down.');
                }
                return $driver === 'pgsql'
                    ? new \PDO('pgsql:host=' . PGSQL_SERVER_HOST . ';port=' . PGSQL_SERVER_PORT . ';dbname=' . PGSQL_SERVER_DB, PGSQL_SERVER_USER, PGSQL_SERVER_PWD)
                    : new \PDO('mysql:host=' . MYSQL_SERVER_HOST . ';port=' . MYSQL_SERVER_PORT . ';dbname=' . MYSQL_SERVER_DB, MYSQL_SERVER_USER, MYSQL_SERVER_PWD);
            });

            self::killPdoConnection($pdo);
            $down = true;
            try {
                $pdo->query('SELECT 42');
                self::fail('The reconnect fails.');
            } catch (\PDOException $e) {
                self::assertSame('The server is down.', $e->getMessage());
                self::assertTrue($pdo->isLost());
            }

            $down = false;
            self::assertEquals(43, $pdo->query('SELECT 43')->fetchColumn());
            self::assertSame(1, $pdo->getRound());
            self::assertFalse($pdo->isLost());
        });
    }

    public static function dataDrivers(): array
    {
        return [
            'MySQL'      => ['mysql'],
            'PostgreSQL' => ['pgsql'],
        ];
    }
}
