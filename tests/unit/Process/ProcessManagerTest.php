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
    public function testAdd()
    {
        $pm = new ProcessManager();
        $counter = new Atomic(0);

        $pm->add(function (Pool $pool, int $workerId) use ($counter) {
            $counter->add();
            $this->assertEquals(0, $workerId);
            if ($counter->get() >= 5) {
                $pool->shutdown();
            }
        });
        $pm->add(function (Pool $pool, int $workerId) use ($counter) {
            $counter->add();
            $this->assertEquals(1, $workerId);
            if ($counter->get() >= 5) {
                $pool->shutdown();
            }
        });
        $pm->start();
    }

    public function testAddDisableCoroutine()
    {
        $pm = new ProcessManager();

        $pm->add(function (Pool $pool, int $workerId) {
            $this->assertEquals(-1, Coroutine::getCid());
            $pool->shutdown();
        });
        $pm->start();
    }

    public function testAddEnableCoroutine()
    {
        $pm = new ProcessManager();

        $pm->add(function (Pool $pool, int $workerId) {
            $this->assertEquals(1, Coroutine::getCid());
            $pool->shutdown();
        }, true);
        $pm->start();
    }

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
