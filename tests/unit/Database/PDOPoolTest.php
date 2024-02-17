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

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Swoole\Tests\HookFlagsTrait;

use function Swoole\Coroutine\go;
use function Swoole\Coroutine\run;

/**
 * Class PDOPoolTest
 *
 * @internal
 * @coversNothing
 */
class PDOPoolTest extends TestCase
{
    use HookFlagsTrait;

    protected static $sqliteDatabaseFile;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$sqliteDatabaseFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'swoole_pdo_pool_sqlite_test.db';
        if (file_exists(self::$sqliteDatabaseFile)) {
            unlink(self::$sqliteDatabaseFile);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (file_exists(self::$sqliteDatabaseFile)) {
            unlink(self::$sqliteDatabaseFile);
        }
        parent::tearDownAfterClass();
    }

    public function testPutWhenErrorHappens()
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        $expect = ['0', '1', '2', '3', '4'];
        $actual = [];
        Coroutine\run(function () use (&$actual) {
            $config = (new PDOConfig())
                ->withHost(MYSQL_SERVER_HOST)
                ->withPort(MYSQL_SERVER_PORT)
                ->withDbName(MYSQL_SERVER_DB)
                ->withCharset('utf8mb4')
                ->withUsername(MYSQL_SERVER_USER)
                ->withPassword(MYSQL_SERVER_PWD)
            ;

            $pool = new PDOPool($config, 2);
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
        run(function () {
            $config = (new PDOConfig())
                ->withDriver('pgsql')
                ->withHost(PGSQL_SERVER_HOST)
                ->withPort(PGSQL_SERVER_PORT)
                ->withDbName(PGSQL_SERVER_DB)
                ->withUsername(PGSQL_SERVER_USER)
                ->withPassword(PGSQL_SERVER_PWD)
            ;
            $pool = new PDOPool($config, 10);

            $pdo = $pool->get();
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
        run(function () {
            $config = (new PDOConfig())
                ->withDriver('oci')
                ->withHost(ORACLE_SERVER_HOST)
                ->withPort(ORACLE_SERVER_PORT)
                ->withDbName(ORACLE_SERVER_DB)
                ->withCharset('AL32UTF8')
                ->withUsername(ORACLE_SERVER_USER)
                ->withPassword(ORACLE_SERVER_PWD)
            ;
            $pool = new PDOPool($config, 10);

            $pdo = $pool->get();
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
        run(function () {
            $config = (new PDOConfig())->withDriver('sqlite')->withDbname(self::$sqliteDatabaseFile);
            $pool   = new PDOPool($config, 10);

            $pdo = $pool->get();
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
}
