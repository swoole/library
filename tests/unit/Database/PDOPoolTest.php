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
