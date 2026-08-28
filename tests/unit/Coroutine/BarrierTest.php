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

use Swoole\Coroutine;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Coroutine\Barrier
 */
class BarrierTest extends TestCase
{
    public function testWait(): void
    {
        self::coRun(function () {
            $barrier = Barrier::make();
            $count   = 0;
            $N       = 4;
            $st      = microtime(true);
            foreach (range(1, $N) as $i) {
                Coroutine::create(function () use ($barrier, &$count) {
                    System::sleep(0.5);
                    $count++;
                });
            }
            Barrier::wait($barrier);

            // Run concurrently the four coroutines take about 0.5 second in total; run one after another they
            // would take 2. The bounds are wide enough to survive a busy scheduler and still fail on that.
            self::assertThat(
                microtime(true) - $st,
                $this->logicalAnd(self::greaterThan(0.49), self::lessThan(1.5)),
                'The four child coroutines run concurrently, taking about 0.5 second in total rather than 4 x 0.5.'
            );
            self::assertEquals($N, $count, 'All four child coroutines have finished execution; the counter is increased to 4.');
        });
    }

    public function testWaitTimeout(): void
    {
        self::coRun(function () {
            $barrier = Barrier::make();
            $count   = 0;
            $N       = 4;
            $st      = microtime(true);
            foreach (range(1, $N) as $i) {
                Coroutine::create(function () use ($barrier, &$count) {
                    System::sleep(0.5);
                    $count++;
                });
            }
            Barrier::wait($barrier, 0.1);
            $et = microtime(true);

            // The counter is what proves the timeout was honored: had Barrier::wait() ignored it, it would
            // have returned once the children finished, with the counter at 4. An upper bound on the elapsed
            // time cannot add to that -- the children sleep 0.5s, so "timeout ignored" and "scheduler was
            // busy" land in the same range -- and asserting one only makes the test measure the scheduler.
            // The lower bound stays just under 0.1 because microtime() and Swoole's timer disagree by a
            // fraction of a millisecond, which made `greaterThan(0.10)` fail on values like 0.09947.
            self::assertEquals(0, $count, 'None of the four child coroutines finishes execution when timeout happens; the counter remains as 0.');
            self::assertGreaterThan(0.09, $et - $st, 'The parent coroutine waits for the timeout instead of returning at once.');
        });
    }

    /**
     * Test without execution switching between coroutines.
     */
    public function testNoCoroutineSwitching(): void
    {
        self::coRun(function () {
            $barrier = Barrier::make();
            $count   = 0;
            $N       = 4;
            foreach (range(1, $N) as $i) {
                Coroutine::create(function () use ($barrier, &$count) {
                    $count++;
                });
            }
            Barrier::wait($barrier);

            self::assertSame($N, $count, 'The parent coroutine keeps running without switching execution to child coroutines.');
        });
    }

    /**
     * Test without any child coroutines created. Ideally we shouldn't use the Barrier class this way.
     */
    public function testWithoutAnyChildCoroutines(): void
    {
        self::coRun(function () {
            $barrier = Barrier::make();
            Barrier::wait($barrier);
            self::assertNull($barrier, 'To check if there is any possible PHP warnings/errors.');
        });
    }

    /**
     * Test with the Barrier object destroyed in a child coroutine. Ideally we shouldn't use the Barrier class this way.
     */
    public function testUnexpectedDestroy(): void
    {
        self::coRun(function () {
            $barrier = Barrier::make();
            $count   = 0;
            Coroutine::create(function () use (&$barrier, &$count) {
                unset($barrier);
                $count++;
            });
            Barrier::wait($barrier);

            self::assertEquals(1, $count, 'Have the Barrier object destroyed unexpected in a child coroutine.');
        });
    }

    /**
     * Test with the Barrier object destroyed in a child coroutine following by a coroutine switching. Ideally we shouldn't use the Barrier class this way.
     */
    public function testUnexpectedDestroyWithCoroutineSwitching(): void
    {
        self::coRun(function () {
            $barrier = Barrier::make();
            $count   = 0;
            $st      = microtime(true);
            Coroutine::create(function () use (&$barrier, &$count) {
                unset($barrier);
                System::sleep(0.5);
                $count++;
            });
            Barrier::wait($barrier);
            $et = microtime(true);

            self::assertEquals(0, $count, 'The counter does not change since the child coroutine not yet finished.');
            self::assertLessThan(0.25, $et - $st, 'The parent coroutine continues exeuction without waiting the child to finish.');
        });
    }
}
