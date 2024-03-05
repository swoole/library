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
use function Swoole\Coroutine\run;

/**
 * Class PDOPoolTest
 *
 * @internal
 * @coversNothing
 */
class PDOPoolTest extends DatabaseTestCase
{
    use HookFlagsTrait;

    public function testPutWhenErrorHappens()
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        $expect = ['0', '1', '2', '3', '4'];
        $actual = [];
        Coroutine\run(function () use (&$actual) {
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
        run(function () {
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
        run(function () {
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
        run(function () {
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

    public function testTimeoutException(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        run(function () {
            $pool      = self::getPdoMysqlPool(1);
            $waitGroup = new WaitGroup(2); // A wait group to wait for the next 2 coroutines to finish.

            go(function () use ($pool, $waitGroup) {
                $pool->get()->exec('SELECT SLEEP(1)'); // Hold the connection for 1 second before putting it back into the pool.
                $waitGroup->done();
            });

            go(function () use ($pool, $waitGroup) {
                Coroutine::sleep(0.1); // Sleep for 0.1 second to ensure the 1st connection is in use by the 1st coroutine.
                self::assertFalse($pool->get(0.5), 'Failed to get a 2nd connection from the pool within 0.5 seconds');
                $waitGroup->done();
            });

            $waitGroup->wait();
            $pool->close();
            self::restoreHookFlags();
        });
    }
}
