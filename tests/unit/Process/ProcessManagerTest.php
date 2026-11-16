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

use Swoole\Atomic;
use Swoole\Coroutine;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Process\ProcessManager
 */
class ProcessManagerTest extends TestCase
{
    public function testShutdownBeforeStartIsHarmless(): void
    {
        self::assertFalse((new ProcessManager())->shutdown());
    }

    public function testStartWithoutWorkersIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        (new ProcessManager())->start();
    }

    public function testInvalidWorkerCountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProcessManager())->addBatch(-1, static function (): void {});
    }

    public function testRunningManagerRejectsReentryAndCanShutdown(): void
    {
        $checked = new Atomic(0);
        $manager = new ProcessManager();
        $manager->add(static function () use ($manager, $checked): void {
            try {
                $manager->start();
            } catch (\LogicException) {
                $checked->add();
            } finally {
                $manager->shutdown();
            }
        });
        $manager->start();
        self::assertSame(1, $checked->get());
        self::assertFalse($manager->shutdown());
    }

    public function testAdd(): void
    {
        $pm     = new ProcessManager();
        $atomic = new Atomic(0);

        $pm->add(function (Pool $pool, int $workerId) use ($atomic) {
            $this->assertEquals(0, $workerId);
            usleep(100000);
            $atomic->wakeup();
        });

        $pm->add(function (Pool $pool, int $workerId) use ($atomic) {
            $this->assertEquals(1, $workerId);
            $atomic->wait(1.5);
            $pool->shutdown();
        });

        $pm->start();
    }

    public function testAddDisableCoroutine(): void
    {
        $pm = new ProcessManager();

        $pm->add(function (Pool $pool, int $workerId) {
            $this->assertEquals(-1, Coroutine::getCid());
            $pool->shutdown();
        });

        $pm->start();
    }

    public function testAddEnableCoroutine(): void
    {
        $pm = new ProcessManager();

        $pm->add(function (Pool $pool, int $workerId) {
            $this->assertGreaterThanOrEqual(1, Coroutine::getCid());
            $pool->shutdown();
        }, true);

        $pm->start();
    }

    public function testAddBatch(): void
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
