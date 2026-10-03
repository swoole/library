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
 * @covers \Swoole\Database\PDOStatementProxy
 */
class PDOStatementProxyTest extends DatabaseTestCase
{
    public function testRun(): void
    {
        self::coRun(function () {
            self::assertFalse(
                self::getPdoMysqlPool()->get()->query("SHOW TABLES like 'NON_EXISTING_TABLE_NAME'")->fetch(\PDO::FETCH_ASSOC),
                'FALSE is returned if no results found.'
            );
        });
    }

    /**
     * @dataProvider dataSetFetchMode
     */
    public function testSetFetchMode(array $expected, array $args, string $message): void
    {
        self::coRun(function () use ($expected, $args, $message) {
            $stmt = self::getPdoMysqlPool()->get()->query(
                'SELECT
                 *
                 FROM (
                     SELECT 1 as col1, 2 as col2
                     UNION SELECT 3, 4
                     UNION SELECT 5, 6
                 ) `table1`'
            );
            $stmt->setFetchMode(...$args);
            self::assertEquals($expected, $stmt->fetchAll(), $message);
        });
    }

    public static function dataSetFetchMode(): array
    {
        return [
            [
                [
                    ['col1' => '1', 'col2' => '2'],
                    ['col1' => '3', 'col2' => '4'],
                    ['col1' => '5', 'col2' => '6'],
                ],
                [\PDO::FETCH_ASSOC],
                'Test the  fetch mode "PDO::FETCH_ASSOC"',
            ],
            [
                [
                    '2',
                    '4',
                    '6',
                ],
                [\PDO::FETCH_COLUMN, 1],
                'Test the  fetch mode "PDO::FETCH_COLUMN"',
            ],
            [
                [
                    (object) ['col1' => '1', 'col2' => '2'],
                    (object) ['col1' => '3', 'col2' => '4'],
                    (object) ['col1' => '5', 'col2' => '6'],
                ],
                [\PDO::FETCH_CLASS, \stdClass::class],
                'Test the  fetch mode "PDO::FETCH_CLASS"',
            ],
        ];
    }

    public function testBindParam(): void
    {
        self::coRun(function () {
            $stmt  = self::getPdoMysqlPool()->get()->prepare('SHOW TABLES like ?');
            $table = 'NON_EXISTING_TABLE_NAME';
            $stmt->bindParam(1, $table, \PDO::PARAM_STR);
            $stmt->execute();
            self::assertIsArray($stmt->fetchAll());
        });
    }

    /**
     * The proxy takes the names of the arguments of PDOStatement, so that a call that passes them by name works on the
     * proxy as it does on PDOStatement.
     */
    public function testArgumentsTakeTheNamesOfPdo(): void
    {
        self::coRun(function () {
            $stmt = self::getPdoSqlitePool()->get()->prepare('SELECT ? AS a, ? AS b');
            self::assertTrue($stmt->setFetchMode(mode: \PDO::FETCH_BOUND));
            self::assertTrue($stmt->bindValue(param: 1, value: 'x', type: \PDO::PARAM_STR));
            $b = 'y';
            self::assertTrue($stmt->bindParam(param: 2, var: $b, type: \PDO::PARAM_STR, maxLength: 0, driverOptions: null));
            self::assertTrue($stmt->execute());

            $a = $c = null;
            self::assertTrue($stmt->bindColumn(column: 'a', var: $a, type: \PDO::PARAM_STR, maxLength: 0, driverOptions: null));
            self::assertTrue($stmt->bindColumn(column: 'b', var: $c));
            self::assertTrue($stmt->fetch());
            self::assertSame(['x', 'y'], [$a, $c]);
        });
    }

    /**
     * A connection lost while the rows are being read cannot be recovered by the proxy: the rows went down with
     * it. The proxy used to reconnect, prepare the statement again and fetch from it without executing it, which
     * yields no rows and no error, so the application saw an empty result for a query that has rows.
     *
     * The lost connection is simulated by a PDOStatement subclass (see the end of this file) that throws the way
     * a lost MySQL connection does, so the test runs against SQLite, deterministically, with no server to kill.
     */
    public function testLostConnectionWhileFetchingIsReported(): void
    {
        self::coRun(function () {
            $failures = new \stdClass();
            $pool     = self::getPdoSqlitePool(1);
            $pdo      = $pool->get();
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [LostConnectionStatement::class, [$failures]]);

            $statement = $pdo->prepare('SELECT 42 AS answer');
            $statement->execute();

            $failures->fetchAll = true;
            try {
                $statement->fetchAll(\PDO::FETCH_ASSOC);
                self::fail('The lost connection is reported instead of being turned into an empty result.');
            } catch (\PDOException $e) {
                self::assertStringContainsString('server has gone away', $e->getMessage());
            }
            self::assertSame(0, $pdo->getRound(), 'There is nothing to retry, so there is no reconnect either.');

            $pool->put($pdo);
            $pool->close();
        });
    }

    /**
     * execute() runs the statement from the start, so it is still retried on a fresh connection.
     */
    public function testLostConnectionOnExecuteIsRetried(): void
    {
        self::coRun(function () {
            $failures = new \stdClass();
            $pool     = self::getPdoSqlitePool(1);
            $pdo      = $pool->get();
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [LostConnectionStatement::class, [$failures]]);

            $statement = $pdo->prepare('SELECT 42 AS answer');

            $failures->execute = true;
            self::assertTrue($statement->execute());
            self::assertSame(1, $pdo->getRound(), 'The proxy reconnected exactly once.');
            self::assertEquals(42, $statement->fetchAll(\PDO::FETCH_ASSOC)[0]['answer'], 'The retried execute() ran for real.');

            $pool->put($pdo);
            $pool->close();
        });
    }

    /**
     * After reconnecting the parent once, the statement has to remember the parent's new round. It did not, so a
     * second lost connection looked like one the parent had already recovered from: the statement was prepared
     * again on the dead connection and the retried execute() failed with the lost connection.
     */
    public function testReconnectsAgainAfterASecondLostConnection(): void
    {
        self::coRun(function () {
            $failures = new \stdClass();
            $pool     = self::getPdoSqlitePool(1);
            $pdo      = $pool->get();
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [LostConnectionStatement::class, [$failures]]);

            $statement = $pdo->prepare('SELECT 42 AS answer');

            $failures->execute = true;
            self::assertTrue($statement->execute());
            self::assertSame(1, $pdo->getRound());

            $failures->execute = true;
            self::assertTrue($statement->execute());
            self::assertSame(2, $pdo->getRound(), 'The second lost connection reconnects the parent again.');

            $pool->put($pdo);
            $pool->close();
        });
    }

    /**
     * A statement prepared again after a reconnect has to be bound to the variable of the caller, as the first one
     * was. It was bound to a copy made by bindParam(), so the retried execute() and every later one ran with the
     * value the variable had at the time of that call, and wrote it without an error.
     */
    public function testBoundParameterFollowsTheVariableAfterAReconnect(): void
    {
        self::coRun(function () {
            $failures = new \stdClass();
            $pool     = self::getPdoSqlitePool(1);
            $pdo      = $pool->get();
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [LostConnectionStatement::class, [$failures]]);

            $answer    = 1;
            $statement = $pdo->prepare('SELECT :answer AS answer');
            $statement->bindParam(':answer', $answer, \PDO::PARAM_INT);

            $answer            = 2;
            $failures->execute = true;
            self::assertTrue($statement->execute());
            self::assertSame(1, $pdo->getRound());
            self::assertEquals(2, $statement->fetchColumn(), 'The retried execute() uses the present value.');

            $answer = 3;
            self::assertTrue($statement->execute());
            self::assertEquals(3, $statement->fetchColumn(), 'So does every execute() after it.');

            $pool->put($pdo);
            $pool->close();
        });
    }

    /**
     * The same for a column bound to a variable, which has to be filled after a reconnect as before it.
     */
    public function testBoundColumnFillsTheVariableAfterAReconnect(): void
    {
        self::coRun(function () {
            $failures = new \stdClass();
            $pool     = self::getPdoSqlitePool(1);
            $pdo      = $pool->get();
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [LostConnectionStatement::class, [$failures]]);

            $answer    = null;
            $statement = $pdo->prepare('SELECT 42 AS answer');
            $statement->bindColumn('answer', $answer);

            $failures->execute = true;
            self::assertTrue($statement->execute());
            self::assertSame(1, $pdo->getRound());
            self::assertTrue($statement->fetch(\PDO::FETCH_BOUND));
            self::assertEquals(42, $answer);

            $pool->put($pdo);
            $pool->close();
        });
    }

    /**
     * A value bound with bindValue() is bound again as a value.
     */
    public function testBoundValueIsKeptAfterAReconnect(): void
    {
        self::coRun(function () {
            $failures = new \stdClass();
            $pool     = self::getPdoSqlitePool(1);
            $pdo      = $pool->get();
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [LostConnectionStatement::class, [$failures]]);

            $statement = $pdo->prepare('SELECT :answer AS answer');
            $statement->bindValue(':answer', 42, \PDO::PARAM_INT);

            $failures->execute = true;
            self::assertTrue($statement->execute());
            self::assertSame(1, $pdo->getRound());
            self::assertEquals(42, $statement->fetchColumn());

            $pool->put($pdo);
            $pool->close();
        });
    }

    /**
     * The same against the servers, MySQL and PostgreSQL, with the connection ended for real. PostgreSQL matters
     * here: PDO reports a connection it has lost as being inside a transaction, which is no reason not to
     * reconnect.
     *
     * @dataProvider dataPools
     */
    public function testLostConnectionOnExecuteIsRetriedOnTheServer(string $pool): void
    {
        self::coRun(function () use ($pool) {
            $pool = self::{$pool}(1);
            $pdo  = $pool->get();

            $statement = $pdo->prepare('SELECT 42');
            self::killPdoConnection($pdo);
            self::assertTrue($statement->execute(), 'A statement prepared before the connection was lost.');
            self::assertEquals(42, $statement->fetchColumn());
            self::assertSame(1, $pdo->getRound());

            self::killPdoConnection($pdo);
            $statement = $pdo->prepare('SELECT 43');
            self::assertTrue($statement->execute(), 'A statement prepared after the connection was lost.');
            self::assertEquals(43, $statement->fetchColumn());
            self::assertSame(2, $pdo->getRound());

            $pool->put($pdo);
            $pool->close();
        });
    }

    /**
     * A connection lost inside a transaction is reported, as the transaction is lost with it. The connection must
     * not stay lost for whoever gets it from the pool next, though: the pool cannot roll it back, and replaces it.
     *
     * @dataProvider dataPools
     */
    public function testConnectionLostInsideATransactionRecoversForTheNextBorrower(string $pool): void
    {
        self::coRun(function () use ($pool) {
            $pool = self::{$pool}(1);
            $pdo  = $pool->get();
            $pdo->beginTransaction();
            self::killPdoConnection($pdo);

            $statement = $pdo->prepare('SELECT 42');
            try {
                $statement->execute();
                self::fail('Inside a transaction the lost connection is reported.');
            } catch (\PDOException) {
                self::assertSame(0, $pdo->getRound());
            }
            $pool->put($pdo);
            $lost = $pdo;
            unset($statement);

            $pdo = $pool->get();
            self::assertNotSame($lost, $pdo, 'The connection was replaced, not reconnected.');
            $statement = $pdo->prepare('SELECT 43');
            self::assertTrue($statement->execute());
            self::assertEquals(43, $statement->fetchColumn());
            self::assertSame(0, $pdo->getRound());

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
}

/**
 * A statement that fails its next execute() or fetchAll() the way a lost MySQL connection does, once per flag set
 * on the object it is constructed with (PDO passes the constructor arguments of PDO::ATTR_STATEMENT_CLASS along,
 * on every prepare(), including the ones the proxies do after a reconnect).
 */
class LostConnectionStatement extends \PDOStatement
{
    // PDO refuses a statement class with a public constructor.
    protected function __construct(private \stdClass $failures)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->loseConnectionIfAsked('execute');
        return parent::execute($params);
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $this->loseConnectionIfAsked('fetchAll');
        return parent::fetchAll($mode, ...$args);
    }

    private function loseConnectionIfAsked(string $method): void
    {
        if (!empty($this->failures->{$method})) {
            $this->failures->{$method} = false;
            throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }
    }
}
