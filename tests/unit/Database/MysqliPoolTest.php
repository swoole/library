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
 * Every test here gets a pool, and thus connections, of its own, and only ever kills its own connections, so
 * the tests can run concurrently with everything else using the MySQL server.
 *
 * @internal
 * @covers \Swoole\Database\MysqliProxy
 * @covers \Swoole\Database\MysqliStatementProxy
 */
class MysqliPoolTest extends DatabaseTestCase
{
    /**
     * The reconnect logic has to work under the default report mode, where a failure is thrown as a
     * mysqli_sql_exception rather than returned as false.
     */
    public function testReconnectsAfterLostConnectionUnderDefaultReportMode(): void
    {
        self::coRun(function () {
            self::assertSame(
                MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT,
                (new \mysqli_driver())->report_mode,
                'PHP 8.1+ throws mysqli errors by default; this test relies on that mode being in effect.'
            );

            $pool   = self::getMysqliPool(1);
            $mysqli = $pool->get();
            self::assertSame(0, $mysqli->getRound());

            self::killMysqliConnection($mysqli);

            $result = $mysqli->query('SELECT 42 AS answer');
            self::assertEquals(42, $result->fetch_assoc()['answer'], 'The query is re-run on a fresh connection.');
            self::assertSame(1, $mysqli->getRound(), 'The proxy reconnected exactly once.');

            $pool->put($mysqli);
            $pool->close();
        });
    }

    /**
     * After a reconnect the statement is prepared again on the new connection, and the variables bound with
     * bind_result() have to be bound again individually, or fetch() fills nothing.
     */
    public function testBindResultSurvivesReconnect(): void
    {
        self::coRun(function () {
            $pool   = self::getMysqliPool(1);
            $mysqli = $pool->get();

            $statement = $mysqli->prepare('SELECT 42');
            $statement->bind_result($answer);
            $statement->execute();
            self::assertTrue($statement->fetch());
            self::assertSame(42, $answer);

            $answer = null;
            self::killMysqliConnection($mysqli);

            $statement->execute();
            self::assertTrue($statement->fetch());
            self::assertSame(42, $answer, 'The variable bound before the reconnect receives the row fetched after it.');
            self::assertSame(1, $mysqli->getRound());

            $pool->put($mysqli);
            $pool->close();
        });
    }

    /**
     * A connection lost while the rows are being read cannot be recovered by the proxy: the rows went down with
     * it. The proxy used to reconnect, prepare the statement again and fetch from it without executing it, which
     * fails with "Commands out of sync" (2014) in place of the lost connection.
     *
     * A loss in the middle of a result set cannot be forced deterministically, so the statement here is a
     * mysqli_stmt subclass (see the end of this file) whose fetch() fails the way a lost connection does.
     */
    public function testLostConnectionWhileFetchingIsReported(): void
    {
        self::coRun(function () {
            $pool   = self::getMysqliPool(1);
            $mysqli = $pool->get();

            $statement = new MysqliStatementProxy(
                new LostConnectionMysqliStatement($mysqli->__getObject(), 'SELECT 42'),
                'SELECT 42',
                $mysqli
            );
            self::assertTrue($statement->execute());
            try {
                $statement->fetch();
                self::fail('The lost connection is reported.');
            } catch (\mysqli_sql_exception $e) {
                self::assertSame(2006, $e->getCode(), 'The lost connection itself is reported, not an error of a retried fetch().');
            }
            self::assertSame(0, $mysqli->getRound(), 'There is nothing to retry, so there is no reconnect either.');

            $pool->put($mysqli);
            $pool->close();
        });
    }

    /**
     * A lost connection inside a transaction is reported, not hidden behind a reconnect: reconnecting would drop
     * what already ran in the transaction and run this call, and the commit(), on a fresh connection outside of
     * any transaction. Outside the transaction the very next call reconnects as usual.
     */
    public function testDoesNotReconnectInsideATransaction(): void
    {
        self::coRun(function () {
            $pool   = self::getMysqliPool(1);
            $mysqli = $pool->get();
            self::assertFalse($mysqli->inTransaction());
            $mysqli->begin_transaction();
            self::assertTrue($mysqli->inTransaction());

            self::killMysqliConnection($mysqli);

            try {
                $mysqli->query('SELECT 1');
                self::fail('The lost connection is reported.');
            } catch (\mysqli_sql_exception $e) {
                self::assertContains($e->getCode(), MysqliProxy::IO_ERRORS, 'The caller sees the connection error itself.');
            }
            self::assertSame(0, $mysqli->getRound(), 'No reconnect inside the transaction.');
            self::assertFalse($mysqli->inTransaction(), 'The transaction died with the connection.');

            self::assertEquals(1, $mysqli->query('SELECT 1')->fetch_row()[0], 'The next call, outside the transaction, reconnects.');
            self::assertSame(1, $mysqli->getRound());

            $pool->put($mysqli);
            $pool->close();
        });
    }

    /**
     * autocommit(false) runs every statement inside an implicit transaction until autocommit is turned back on;
     * commit() and rollback() only end the current one. The connection counts as in a transaction the whole time.
     */
    public function testAutocommitOffCountsAsATransaction(): void
    {
        self::coRun(function () {
            $pool   = self::getMysqliPool(1);
            $mysqli = $pool->get();

            $mysqli->autocommit(false);
            self::assertTrue($mysqli->inTransaction());
            $mysqli->commit();
            self::assertTrue($mysqli->inTransaction(), 'The next statement starts another implicit transaction.');
            $mysqli->autocommit(true);
            self::assertFalse($mysqli->inTransaction());

            $mysqli->autocommit(false);
            self::killMysqliConnection($mysqli);
            try {
                $mysqli->query('SELECT 1');
                self::fail('The lost connection is reported.');
            } catch (\mysqli_sql_exception $e) {
                self::assertContains($e->getCode(), MysqliProxy::IO_ERRORS);
            }
            self::assertSame(0, $mysqli->getRound());
            self::assertFalse($mysqli->inTransaction());

            $pool->put($mysqli);
            $pool->close();
        });
    }

    /**
     * A statement executed inside the transaction does not reconnect its parent either, and afterwards, outside
     * the transaction, it reconnects the parent and is prepared again as usual.
     */
    public function testStatementDoesNotReconnectInsideATransaction(): void
    {
        self::coRun(function () {
            $pool   = self::getMysqliPool(1);
            $mysqli = $pool->get();
            $mysqli->begin_transaction();
            $statement = $mysqli->prepare('SELECT 1');

            self::killMysqliConnection($mysqli);

            try {
                $statement->execute();
                self::fail('The lost connection is reported.');
            } catch (\mysqli_sql_exception $e) {
                self::assertContains($e->getCode(), MysqliProxy::IO_ERRORS);
            }
            self::assertSame(0, $mysqli->getRound(), 'No reconnect inside the transaction.');
            self::assertFalse($mysqli->inTransaction(), 'The transaction died with the connection.');

            self::assertTrue($statement->execute(), 'Outside the transaction the statement reconnects its parent and is prepared again.');
            self::assertSame(1, $mysqli->getRound());

            $pool->put($mysqli);
            $pool->close();
        });
    }
}

/**
 * A prepared statement whose fetch() fails the way it does on a lost connection under the default report mode.
 */
class LostConnectionMysqliStatement extends \mysqli_stmt
{
    public function fetch(): ?bool
    {
        throw new \mysqli_sql_exception('MySQL server has gone away', 2006);
    }
}
