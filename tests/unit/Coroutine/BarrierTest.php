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
    /**
     * @covers \Swoole\Coroutine\Barrier
     */
    public function testWait()
    {
        run(function () {
            $barrier = Barrier::make();
            $count   = 0;
            $N       = 4;
            $st      = microtime(true);
            foreach (range(1, $N) as $i) {
                \Swoole\Coroutine::create(function () use ($barrier, &$count) {
                    System::sleep(0.5);
                    $count++;
                });
            }
            Barrier::wait($barrier);

            self::assertEqualsWithDelta(microtime(true), $st + 0.525, 0.025, 'It takes about 0.50 to 0.55 second to finish execution of the four child coroutines.');
            self::assertEquals($N, $count, 'All four child coroutines have finished execution; the counter is increased to 4.');
        });
    }

    /**
     * @covers \Swoole\Coroutine\Barrier
     */
    public function testWaitTimeout()
    {
        run(function () {
            $barrier = Barrier::make();
            $count   = 0;
            $N       = 4;
            $st      = microtime(true);
            foreach (range(1, $N) as $i) {
                \Swoole\Coroutine::create(function () use ($barrier, &$count) {
                    System::sleep(0.5);
                    $count++;
                });
            }
            Barrier::wait($barrier, 0.1);
            $et = microtime(true);

            $this->assertEquals(0, $count, 'None of the four child coroutines finishes execution when timeout happens; the counter remains as 0.');
            $this->assertThat(
                $et - $st,
                $this->logicalAnd($this->greaterThan(0.10), $this->lessThan(0.15)),
                'The parent coroutine stops waiting when timeout happens.'
            );
        });
    }

    /**
     * Test without execution switching between coroutines.
     *
     * @covers \Swoole\Coroutine\Barrier
     */
    public function testNoCoroutineSwitching()
    {
        run(function () {
            $barrier = Barrier::make();
            $count   = 0;
            $N       = 4;
            foreach (range(1, $N) as $i) {
                \Swoole\Coroutine::create(function () use ($barrier, &$count) {
                    $count++;
                });
            }
            Barrier::wait($barrier);

            $this->assertSame($N, $count, 'The parent coroutine keeps running without switching execution to child coroutines.');
        });
    }

    /**
     * Test without any child coroutines created. Ideally we shouldn't use the Barrier class this way.
     *
     * @covers \Swoole\Coroutine\Barrier
     */
    public function testWithoutAnyChildCoroutines()
    {
        run(function () {
            $barrier = Barrier::make();
            Barrier::wait($barrier);
            $this->assertNull($barrier, 'To check if there is any possible PHP warnings/errors.');
        });
    }

    /**
     * Test with the Barrier object destroyed in a child coroutine. Ideally we shouldn't use the Barrier class this way.
     *
     * @covers \Swoole\Coroutine\Barrier
     */
    public function testUnexpectedDestroy()
    {
        run(function () {
            $barrier = Barrier::make();
            $count   = 0;
            \Swoole\Coroutine::create(function () use (&$barrier, &$count) {
                unset($barrier);
                $count++;
            });
            Barrier::wait($barrier);

            $this->assertEquals(1, $count, 'Have the Barrier object destroyed unexpected in a child coroutine.');
        });
    }

    /**
     * Test with the Barrier object destroyed in a child coroutine following by a coroutine switching. Ideally we shouldn't use the Barrier class this way.
     *
     * @covers \Swoole\Coroutine\Barrier
     */
    public function testUnexpectedDestroyWithCoroutineSwitching()
    {
        run(function () {
            $barrier = Barrier::make();
            $count   = 0;
            $st      = microtime(true);
            \Swoole\Coroutine::create(function () use (&$barrier, &$count) {
                unset($barrier);
                System::sleep(0.5);
                $count++;
            });
            Barrier::wait($barrier);
            $et = microtime(true);

            $this->assertEquals(0, $count, 'The counter does not change since the child coroutine not yet finished.');
            $this->lessThan(0.25, $et - $st, 'The parent coroutine continues exeuction without waiting the child to finish.');
        });
    }
}
