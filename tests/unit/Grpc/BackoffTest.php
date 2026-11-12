<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Grpc;

use Swoole\Grpc\Internal\Backoff;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Internal\Backoff
 */
class BackoffTest extends TestCase
{
    public function testExponentialGrowthWithoutJitter(): void
    {
        $b = new Backoff(0.1, 2.0, 5.0, 0.0);
        $this->assertEqualsWithDelta(0.1, $b->delayFor(0), 1e-9);
        $this->assertEqualsWithDelta(0.2, $b->delayFor(1), 1e-9);
        $this->assertEqualsWithDelta(0.4, $b->delayFor(2), 1e-9);
        $this->assertEqualsWithDelta(0.8, $b->delayFor(3), 1e-9);
    }

    public function testDelayIsCappedAtMax(): void
    {
        $b = new Backoff(0.1, 2.0, 0.5, 0.0);
        $this->assertLessThanOrEqual(0.5, $b->delayFor(100));
        $this->assertEqualsWithDelta(0.5, $b->delayFor(100), 1e-9);
    }

    public function testJitterStaysWithinBounds(): void
    {
        $b = new Backoff(1.0, 2.0, 10.0, 0.5);
        for ($i = 0; $i < 100; $i++) {
            $delay = $b->delayFor(2); // base 4.0
            $this->assertGreaterThanOrEqual(2.0, $delay); // 4 * (1-0.5)
            $this->assertLessThanOrEqual(6.0, $delay);    // 4 * (1+0.5)
        }
    }

    public function testZeroInitialAndSingleMultiplier(): void
    {
        $b = new Backoff(0.0, 1.0, 1.0, 0.0);
        $this->assertSame(0.0, $b->delayFor(0));
        $this->assertSame(0.0, $b->delayFor(5));
    }

    public function testAttemptCounterAndReset(): void
    {
        // delayFor() does not advance the counter; only sleep() does.
        $b = new Backoff(0.0, 2.0, 1.0, 0.0);
        $this->assertSame(0, $b->getAttempt());
        $b->sleep();
        $this->assertSame(1, $b->getAttempt());
        $b->sleep();
        $this->assertSame(2, $b->getAttempt());
        $b->reset();
        $this->assertSame(0, $b->getAttempt());
    }
}
