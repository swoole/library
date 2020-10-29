<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class BarrierTest extends TestCase
{
    public function testWait()
    {
        run(function () {
            $barrier = Barrier::make();
            $count = 0;
            $N = 4;
            $st = microtime(true);
            foreach (range(1, $N) as $i) {
                \Swoole\Coroutine::create(function () use ($barrier, &$count) {
                    System::sleep(0.5);
                    $count++;
                });
            }
            Barrier::wait($barrier);
            $et = microtime(true);

            $this->assertEquals($count, $N);
            $this->assertLessThan($et - $st, 0.5);
            $this->assertGreaterThan($et - $st, 0.55);
        });
    }

    public function testWaitTimeout()
    {
        run(function () {
            $barrier = Barrier::make();
            $count = 0;
            $N = 4;
            $st = microtime(true);
            foreach (range(1, $N) as $i) {
                \Swoole\Coroutine::create(function () use ($barrier, &$count) {
                    System::sleep(0.5);
                    $count++;
                });
            }
            Barrier::wait($barrier, 0.1);
            $et = microtime(true);

            $this->assertEquals($count, 0);
            $this->assertLessThan($et - $st, 0.1);
            $this->assertGreaterThan($et - $st, 0.15);
        });
    }

    public function testSubCoNoUseCo()
    {
        run(function () {
            $barrier = Barrier::make();
            $count = 0;
            $N = 4;
            foreach (range(1, $N) as $i) {
                \Swoole\Coroutine::create(function () use ($barrier, &$count) {
                    $count++;
                });
            }
            Barrier::wait($barrier);

            $this->assertEquals($count, $N);
        });
    }
}
