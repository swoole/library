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

            $this->assertEquals($count, $N, 'All four child coroutines have finished execution; the counter is increased to 4.');
            $this->assertThat(
                $et - $st,
                $this->logicalAnd($this->greaterThan(0.50), $this->lessThan(0.55)),
                'It takes barely over 0.5 second to finish execution of the four child coroutines.'
            );
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

            $this->assertEquals($count, 0, 'None of the four child coroutines finishes execution when timeout happens; the counter remains as 0.');
            $this->assertThat(
                $et - $st,
                $this->logicalAnd($this->greaterThan(0.10), $this->lessThan(0.15)),
                'The parent coroutine stops waiting when timeout happens.'
            );
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

            $this->assertEquals($count, $N, 'The parent coroutine keeps running without switching execution to child coroutines.');
        });
    }
}
