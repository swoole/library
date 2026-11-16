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
 * @covers \Swoole\Database\MysqliProxy
 * @covers \Swoole\Database\MysqliStatementProxy
 * @covers \Swoole\Database\PDOProxy
 * @covers \Swoole\Database\PDOStatementProxy
 */
class ReplaySafetyTest extends DatabaseTestCase
{
    /**
     * Simulate a server that committed the write but whose response was lost.
     * @dataProvider statementModes
     */
    public function testPdoDoesNotReplayAnAcknowledgementLostAfterAWrite(bool $prepared): void
    {
        self::coRun(static function () use ($prepared): void {
            $file  = tempnam(sys_get_temp_dir(), 'pdo-replay-');
            $state = (object) ['fail' => true];
            $pdo   = new PDOProxy(static function () use ($file, $state): \PDO {
                $native = new class('sqlite:' . $file, $state) extends \PDO {
                    public function __construct(string $dsn, private object $state)
                    {
                        parent::__construct($dsn);
                    }

                    public function exec(string $statement): int|false
                    {
                        $result = parent::exec($statement);
                        if (str_starts_with($statement, 'INSERT') && $this->state->fail) {
                            $this->state->fail = false;
                            throw new \PDOException('MySQL server has gone away');
                        }
                        return $result;
                    }
                };
                $native->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [AcknowledgementLostPdoStatement::class, [$state]]);
                return $native;
            });
            try {
                $pdo->exec('CREATE TABLE writes(id INTEGER)');
                try {
                    if ($prepared) {
                        $pdo->prepare('INSERT INTO writes VALUES (1)')->execute();
                    } else {
                        $pdo->exec('INSERT INTO writes VALUES (1)');
                    }
                    self::fail('An ambiguous write must be reported');
                } catch (\PDOException) {
                    self::assertSame(0, $pdo->getRound());
                }
                self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM writes')->fetchColumn());
                self::assertSame(1, $pdo->getRound(), 'A new explicit call can reconnect');
            } finally {
                unset($pdo);
                unlink($file);
            }
        });
    }

    /** @dataProvider statementModes */
    public function testMysqliDoesNotReplayAnAcknowledgementLostAfterAWrite(bool $prepared): void
    {
        self::coRun(static function () use ($prepared): void {
            $state = (object) ['fail' => true];
            $proxy = new MysqliProxy(static function () use ($state): \mysqli {
                return new class($state) extends \mysqli {
                    public function __construct(private object $state)
                    {
                        parent::__construct(MYSQL_SERVER_HOST, MYSQL_SERVER_USER, MYSQL_SERVER_PWD, MYSQL_SERVER_DB, MYSQL_SERVER_PORT);
                    }

                    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): \mysqli_result|bool
                    {
                        $result = parent::query($query, $result_mode);
                        if (str_starts_with($query, 'INSERT') && $this->state->fail) {
                            $this->state->fail = false;
                            throw new \mysqli_sql_exception('Lost connection to MySQL server during query', 2013);
                        }
                        return $result;
                    }
                };
            });
            $table = 'review_replay_' . uniqid();
            $proxy->query("CREATE TABLE {$table}(id INT)");
            try {
                try {
                    if ($prepared) {
                        $statement = new AcknowledgementLostMysqliStatement($proxy->__getObject(), "INSERT INTO {$table} VALUES(1)", $state);
                        (new MysqliStatementProxy($statement, "INSERT INTO {$table} VALUES(1)", $proxy))->execute();
                    } else {
                        $proxy->query("INSERT INTO {$table} VALUES(1)");
                    }
                    self::fail('An ambiguous write must be reported');
                } catch (\mysqli_sql_exception) {
                    self::assertSame(0, $proxy->getRound());
                }
                self::assertSame('1', $proxy->query("SELECT COUNT(*) FROM {$table}")->fetch_row()[0]);
                self::assertSame(1, $proxy->getRound());
            } finally {
                $proxy->query("DROP TABLE {$table}");
                $proxy->close();
            }
        });
    }

    public static function statementModes(): array
    {
        return [[false], [true]];
    }

    public function testCloseDoesNotReconnectEvenWhenRetriesAreEnabled(): void
    {
        $constructed = 0;
        $proxy       = new MysqliProxy(static function () use (&$constructed): \mysqli {
            $constructed++;
            return new class extends \mysqli {
                public int $closes = 0;

                public function __construct()
                {
                }

                public function close(): true
                {
                    $this->closes++;
                    throw new \mysqli_sql_exception('Connection lost', 2013);
                }
            };
        });
        $proxy->setRetryOnLostConnection(true);
        try {
            $proxy->close();
            self::fail('The original close failure must be reported');
        } catch (\mysqli_sql_exception) {
            self::assertSame(1, $constructed);
            self::assertSame(1, $proxy->__getObject()->closes);
        }
    }

    public function testChangeUserPasswordIsRedactedFromDebugOutput(): void
    {
        self::coRun(static function (): void {
            $pool       = self::getMysqliPool(1);
            $connection = $pool->get();
            $connection->change_user(MYSQL_SERVER_USER, MYSQL_SERVER_PWD, MYSQL_SERVER_DB);
            $context = (new \ReflectionObject($connection))->getProperty('changeUserContext')->getValue($connection);
            self::assertInstanceOf(\SensitiveParameterValue::class, $context[1]);
            self::assertSame(MYSQL_SERVER_PWD, $context[1]->getValue());
            self::assertSame([], get_object_vars($context[1]), 'Debugging must not expose a public password property');
            self::killMysqliConnection($connection);
            self::assertSame('1', $connection->query('SELECT 1')->fetch_row()[0]);
            $pool->put($connection);
            $pool->close();
        });
    }

    public function testPoolRetryPolicyIsRestoredForEachBorrower(): void
    {
        self::coRun(static function (): void {
            foreach ([PDOConfig::class => PDOPool::class, MysqliConfig::class => MysqliPool::class] as $configClass => $poolClass) {
                $config = (new $configClass())->withHost(MYSQL_SERVER_HOST)->withPort(MYSQL_SERVER_PORT)
                    ->withDbname(MYSQL_SERVER_DB)->withUsername(MYSQL_SERVER_USER)->withPassword(MYSQL_SERVER_PWD)
                ;
                $pool       = new $poolClass($config, 1);
                $connection = $pool->get();
                self::assertFalse($connection->shouldRetryOnLostConnection());
                $connection->setRetryOnLostConnection(true);
                $pool->put($connection);
                $connection = $pool->get();
                self::assertFalse($connection->shouldRetryOnLostConnection());
                $pool->put($connection);
                $config->withRetryOnLostConnection(true);
                $connection = $pool->get();
                self::assertTrue($connection->shouldRetryOnLostConnection());
                $pool->put($connection);
                $pool->close();
            }
        });
    }

    public function testNonConnectionWarningsAreNotSuppressed(): void
    {
        $proxy = new MysqliProxy(static fn () => new class extends \mysqli {
            public function __construct()
            {
            }

            public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): \mysqli_result|bool
            {
                trigger_error('review parameter warning', E_USER_WARNING);
                return true;
            }
        });
        $visible = false;
        set_error_handler(static function (int $severity, string $message) use (&$visible): bool {
            if ($message !== 'review parameter warning') {
                return false;
            }
            $visible = (error_reporting() & $severity) !== 0;
            return true;
        });
        try {
            $proxy->query('SELECT 1');
            self::assertTrue($visible);
        } finally {
            restore_error_handler();
        }
    }

    /** @dataProvider invalidSql */
    public function testFalsyPreparedQueriesArePreparedAgain(string $query, int $expectedError): void
    {
        self::coRun(static function () use ($query, $expectedError): void {
            $pool      = self::getMysqliPool(1);
            $parent    = $pool->get();
            $statement = new class($parent->__getObject()) extends \mysqli_stmt {
                public function execute(?array $params = null): bool
                {
                    throw new \mysqli_sql_exception('Connection lost', 2013);
                }
            };
            try {
                (new MysqliStatementProxy($statement, $query, $parent))->execute();
                self::fail('Preparing invalid SQL must fail');
            } catch (\mysqli_sql_exception $e) {
                self::assertSame($expectedError, $e->getCode());
            } finally {
                $pool->put($parent);
                $pool->close();
            }
        });
    }

    public static function invalidSql(): array
    {
        return [['0', 1064], ['', 1065]];
    }
}

/** @internal */
class AcknowledgementLostPdoStatement extends \PDOStatement
{
    protected function __construct(private object $state)
    {
    }

    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if ($this->state->fail && str_starts_with($this->queryString, 'INSERT')) {
            $this->state->fail = false;
            throw new \PDOException('MySQL server has gone away');
        }
        return $result;
    }
}

/** @internal */
class AcknowledgementLostMysqliStatement extends \mysqli_stmt
{
    public function __construct(\mysqli $connection, string $query, private object $state)
    {
        parent::__construct($connection, $query);
    }

    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if ($this->state->fail) {
            $this->state->fail = false;
            throw new \mysqli_sql_exception('Connection lost', 2013);
        }
        return $result;
    }
}
