<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Process;

use PHPUnit\Framework\TestCase;
use Swoole\Atomic;
use Swoole\Coroutine;

/**
 * Class ProcessManagerTest
 *
 * @internal
 * @coversNothing
 */
class ProcessManagerTest extends TestCase
{
    /**
     * @covers \Swoole\Process\ProcessManager::add
     */
    public function testAdd()
    {
        $pm = new ProcessManager();
        $atomic = new Atomic(0);

        $pm->add(function (Pool $pool, int $workerId) use ($atomic) {
            $this->assertEquals(0, $workerId);
            sleep(0.1);
            $atomic->wakeup();
        });

        $pm->add(function (Pool $pool, int $workerId) use ($atomic) {
            $this->assertEquals(1, $workerId);
            $atomic->wait(1.5);
            $pool->shutdown();
        });

        $pm->start();
    }

    /**
     * @covers \Swoole\Process\ProcessManager::add
     */
    public function testAddDisableCoroutine()
    {
        $pm = new ProcessManager();

        $pm->add(function (Pool $pool, int $workerId) {
            $this->assertEquals(-1, Coroutine::getCid());
            $pool->shutdown();
        });

        $pm->start();
    }

    /**
     * @covers \Swoole\Process\ProcessManager::add
     */
    public function testAddEnableCoroutine()
    {
        $pm = new ProcessManager();

        $pm->add(function (Pool $pool, int $workerId) {
            $this->assertEquals(1, Coroutine::getCid());
            $pool->shutdown();
        }, true);

        $pm->start();
    }

    /**
     * @covers \Swoole\Process\ProcessManager::addBatch
     */
    public function testAddBatch()
    {
        $pm = new ProcessManager();

        $pm->addBatch(2, function (Pool $pool, int $workerId) {
            if ($workerId == 1) {
                $pool->shutdown();
            }
        });

        $pm->start();
    }
}
