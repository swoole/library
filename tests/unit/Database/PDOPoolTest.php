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

use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Swoole\Tests\DatabaseTestCase;
use Swoole\Tests\HookFlagsTrait;

use function Swoole\Coroutine\go;

/**
 * Class PDOPoolTest
 *
 * @internal
 * @covers \Swoole\Database\PDOPool
 */
class PDOPoolTest extends DatabaseTestCase
{
    use HookFlagsTrait;

    public function testPutWhenErrorHappens(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        $expect = ['0', '1', '2', '3', '4'];
        $actual = [];
        self::coRun(function () use (&$actual) {
            $pool = self::getPdoMysqlPool(2);
            for ($n = 5; $n--;) {
                Coroutine::create(function () use ($pool, $n, &$actual) {
                    $pdo = $pool->get();
                    try {
                        $statement = $pdo->prepare('SELECT :n as n');
                        $statement->execute([':n' => $n]);
                        $row = $statement->fetch(\PDO::FETCH_ASSOC);
                        // simulate error happens
                        $statement = $pdo->prepare('KILL CONNECTION_ID()');
                        $statement->execute();
                    } catch (\PDOException) {
                        // do nothing
                    }
                    $pdo = null;
                    $pool->put(null);

                    $actual[] = $row['n'];
                });
            }
        });
        sort($actual);
        $this->assertEquals($expect, $actual);
        self::restoreHookFlags();
    }

    public function testPostgres(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        self::coRun(function () {
            $pool = self::getPdoPgsqlPool(10);
            $pdo  = $pool->get();
            $pdo->exec('CREATE TABLE IF NOT EXISTS test(id INT);');
            $pool->put($pdo);

            $waitGroup = new WaitGroup();
            for ($i = 0; $i < 30; $i++) {
                go(function () use ($pool, $i, $waitGroup) {
                    $waitGroup->add();
                    $pdo       = $pool->get();
                    $statement = $pdo->prepare('INSERT INTO test VALUES(?)');
                    $statement->execute([$i]);

                    $statement = $pdo->prepare('SELECT id FROM test where id = ?');
                    $statement->execute([$i]);
                    $result = $statement->fetch(\PDO::FETCH_ASSOC);
                    $this->assertEquals($result['id'], $i);
                    $pool->put($pdo);
                    $waitGroup->done();
                });
            }

            $waitGroup->wait();
            $pool->close();
            self::restoreHookFlags();
        });
    }

    public function testOracle(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        self::coRun(function () {
            $pool = self::getPdoOraclePool(10);
            $pdo  = $pool->get();
            try {
                $pdo->exec('DROP TABLE test PURGE');
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), 'ORA-00942')) { // ORA-00942: table or view does not exist
                    throw $e;
                }
            }
            $pdo->exec('CREATE TABLE test(id INTEGER)');
            $pool->put($pdo);

            $waitGroup = new WaitGroup();
            for ($i = 0; $i < 30; $i++) {
                go(function () use ($pool, $i, $waitGroup) {
                    $waitGroup->add();
                    $pdo       = $pool->get();
                    $statement = $pdo->prepare('INSERT INTO test VALUES(?)');
                    $statement->execute([$i]);

                    $statement = $pdo->prepare('SELECT id FROM test where id = ?');
                    $statement->execute([$i]);
                    $result = $statement->fetch(\PDO::FETCH_ASSOC);
                    $this->assertEquals($result['ID'], $i);
                    $pool->put($pdo);
                    $waitGroup->done();
                });
            }

            $waitGroup->wait();
            $pool->close();
            self::restoreHookFlags();
        });
    }

    public function testSqlite(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        self::coRun(function () {
            $pool = self::getPdoSqlitePool(10);
            $pdo  = $pool->get();
            $pdo->exec('CREATE TABLE IF NOT EXISTS test(id INT);');
            $pool->put($pdo);

            $waitGroup = new WaitGroup();
            for ($i = 0; $i < 30; $i++) {
                go(function () use ($pool, $i, $waitGroup) {
                    $waitGroup->add();
                    $pdo       = $pool->get();
                    $statement = $pdo->prepare('INSERT INTO test VALUES(?)');
                    $statement->execute([$i]);

                    $statement = $pdo->prepare('SELECT id FROM test where id = ?');
                    $statement->execute([$i]);
                    $result = $statement->fetch(\PDO::FETCH_ASSOC);
                    $this->assertEquals($result['id'], $i);
                    $pool->put($pdo);
                    $waitGroup->done();
                });
            }

            $waitGroup->wait();
            $pool->close();
            self::restoreHookFlags();
        });
    }

    /**
     * A connection put back with a transaction still open is rolled back before it is reused.
     */
    public function testPutRollsBackAnOpenTransaction(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        self::coRun(function () {
            $pool = self::getPdoSqlitePool(1);
            $pdo  = $pool->get();
            $pdo->exec('CREATE TABLE IF NOT EXISTS test_rollback(id INT)');

            $pdo->beginTransaction();
            $pdo->exec('INSERT INTO test_rollback VALUES(1)');
            self::assertTrue($pdo->inTransaction());
            $pool->put($pdo);

            $again = $pool->get();
            self::assertSame($pdo, $again, 'The connection was reused, not replaced.');
            self::assertFalse($again->inTransaction());
            self::assertFalse($again->__getObject()->inTransaction());
            self::assertSame(0, (int) $again->query('SELECT COUNT(*) FROM test_rollback')->fetchColumn());

            $pool->put($again);
            $pool->close();
            self::restoreHookFlags();
        });
    }

    /**
     * A callback of withConnection() that throws inside a transaction leaves nothing behind: put() rolls the
     * transaction back, and the connection is reused, not replaced.
     */
    public function testWithConnectionRollsBackWhenTheCallbackThrows(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        self::coRun(function () {
            $pool = self::getPdoSqlitePool(1);
            $used = null;
            try {
                $pool->withConnection(function (PDOProxy $pdo) use (&$used): void {
                    $used = $pdo;
                    $pdo->exec('CREATE TABLE IF NOT EXISTS test_with_connection(id INT)');
                    $pdo->beginTransaction();
                    $pdo->exec('INSERT INTO test_with_connection VALUES(1)');
                    throw new \RuntimeException('failed');
                });
            } catch (\RuntimeException $e) {
                self::assertSame('failed', $e->getMessage());
            }

            $pool->withConnection(function (PDOProxy $pdo) use ($used): void {
                self::assertSame($used, $pdo, 'The connection was reused, not replaced.');
                self::assertFalse($pdo->__getObject()->inTransaction());
                self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM test_with_connection')->fetchColumn());
            });
            $pool->close();
            self::restoreHookFlags();
        });
    }

    /**
     * A transaction started by hand is not tracked by the proxy, but the driver reports it.
     */
    public function testPutRollsBackATransactionStartedByHand(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        self::coRun(function () {
            $pool = self::getPdoMysqlPool(1);
            $pdo  = $pool->get();
            $pdo->exec('CREATE TEMPORARY TABLE swoole_library_test_by_hand (id INT)');

            $pdo->exec('START TRANSACTION');
            $pdo->exec('INSERT INTO swoole_library_test_by_hand VALUES (1)');
            self::assertFalse($pdo->inTransaction());
            self::assertTrue($pdo->__getObject()->inTransaction());
            $pool->put($pdo);

            $again = $pool->get();
            self::assertSame($pdo, $again, 'The connection was reused, not replaced.');
            self::assertFalse($again->__getObject()->inTransaction());
            self::assertSame(0, (int) $again->query('SELECT COUNT(*) FROM swoole_library_test_by_hand')->fetchColumn());

            $pool->put($again);
            $pool->close();
            self::restoreHookFlags();
        });
    }

    /**
     * A transaction the proxy counts may have ended behind its back. There is nothing to roll back then, and the
     * connection is as good as any.
     */
    public function testPutKeepsAConnectionWhoseTransactionEndedAlready(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        self::coRun(function () {
            $pool = self::getPdoMysqlPool(1);
            $pdo  = $pool->get();

            $pdo->beginTransaction();
            $pdo->exec('COMMIT');
            self::assertTrue($pdo->inTransaction());
            self::assertFalse($pdo->__getObject()->inTransaction());
            $pool->put($pdo);

            $again = $pool->get();
            self::assertSame($pdo, $again, 'The connection was reused, not replaced.');
            self::assertFalse($again->inTransaction());
            self::assertSame(0, $again->getRound());

            $pool->put($again);
            $pool->close();
            self::restoreHookFlags();
        });
    }

    /**
     * When the connection cannot be rolled back and no other one can be made, put() leaves the failure to the
     * next get().
     */
    public function testPutDoesNotThrowWhenTheReplacementCannotBeMade(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        self::coRun(function () {
            $config = (new PDOConfig())
                ->withHost(MYSQL_SERVER_HOST)
                ->withPort(MYSQL_SERVER_PORT)
                ->withDbName(MYSQL_SERVER_DB)
                ->withUsername(MYSQL_SERVER_USER)
                ->withPassword(MYSQL_SERVER_PWD)
            ;
            $pool = new PDOPool($config, 1);
            $pdo  = $pool->get();
            $pdo->beginTransaction();
            self::killPdoConnection($pdo);

            $config->withPort(1);
            $pool->put($pdo);
            try {
                $pool->get();
                self::fail('There is no server to connect to.');
            } catch (\PDOException) {
                // The failure is reported to whoever asks for a connection.
            }

            $config->withPort(MYSQL_SERVER_PORT);
            $again = $pool->get();
            self::assertNotSame($pdo, $again);
            self::assertEquals(1, $again->query('SELECT 1')->fetchColumn());

            $pool->put($again);
            $pool->close();
            self::restoreHookFlags();
        });
    }

    public function testTimeoutException(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        self::coRun(function () {
            // The pool is exhausted synchronously rather than by racing two coroutines against a 0.1s sleep.
            // ConnectionPool::make() increments its counter before the connection's constructor returns and
            // only pushes to the channel afterwards, so a second coroutine arriving inside that window saw
            // the pool as full, parked on the channel, and was handed the new connection -- leaving the
            // first coroutine blocked forever and this assertion inverted.
            $pool = self::getPdoMysqlPool(1);
            $held = $pool->get(); // The pool's only connection, taken before anything else can ask for it.
            self::assertNotFalse($held, 'The pool hands out its only connection.');

            self::assertFalse($pool->get(0.5), 'Failed to get a 2nd connection from the pool within 0.5 seconds');

            $pool->put($held);
            $pool->close();
            self::restoreHookFlags();
        });
    }
}
