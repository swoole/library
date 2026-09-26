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
