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

            self::killConnection($mysqli);

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
            self::killConnection($mysqli);

            $statement->execute();
            self::assertTrue($statement->fetch());
            self::assertSame(42, $answer, 'The variable bound before the reconnect receives the row fetched after it.');
            self::assertSame(1, $mysqli->getRound());

            $pool->put($mysqli);
            $pool->close();
        });
    }

    /**
     * Kills the server-side thread of a pooled connection from a second connection, so that the proxy's next
     * call runs into a lost connection. Killing it from the connection itself would make that very call fail
     * with a non-IO error instead, which is not the situation these tests are about.
     */
    private static function killConnection(MysqliProxy $connection): void
    {
        $admin = new \mysqli(MYSQL_SERVER_HOST, MYSQL_SERVER_USER, MYSQL_SERVER_PWD, MYSQL_SERVER_DB, MYSQL_SERVER_PORT);
        try {
            $admin->query('KILL ' . $connection->thread_id);
        } finally {
            $admin->close();
        }
    }
}
