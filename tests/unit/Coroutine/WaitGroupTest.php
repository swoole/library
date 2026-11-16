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

use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Coroutine\WaitGroup
 */
class WaitGroupTest extends TestCase
{
    public function testNegativeInitialCounterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WaitGroup(-1);
    }

    public function testWaitingFlagIsResetAfterAnException(): void
    {
        self::coRun(function (): void {
            $wg = new class(1) extends WaitGroup {
                public function __construct(int $delta)
                {
                    parent::__construct($delta);
                    $this->chan = new class(1) extends Channel {
                        public function pop(float $timeout = -1): mixed
                        {
                            throw new \RuntimeException('pop failed');
                        }
                    };
                }
            };
            try {
                $wg->wait();
                self::fail('pop() must fail');
            } catch (\RuntimeException $e) {
                self::assertSame('pop failed', $e->getMessage());
            }
            $wg->add();
            $wg->done();
            $wg->done();
            self::assertTrue($wg->wait());
        });
    }

    public function testWait(): void
    {
        self::coRun(function () {
            $wg = new WaitGroup(4);
            $N  = 4;
            $st = microtime(true);
            foreach (range(1, $N) as $i) {
                \Swoole\Coroutine::create(function () use ($wg) {
                    System::sleep(0.5);
                    $wg->done();
                });
            }
            $this->assertEquals($N, $wg->count(), 'Four active coroutines in sleeping state (not yet finished execution).');

            $wg->wait();

            // Run concurrently the four coroutines take about 0.5 second in total; run one after another they
            // would take 2. The previous +-0.025s window had no headroom and failed on 0.5504s.
            self::assertThat(
                microtime(true) - $st,
                $this->logicalAnd(self::greaterThan(0.49), self::lessThan(1.5)),
                'The four coroutines run concurrently, taking about 0.5 second in total rather than 4 x 0.5.'
            );
            $this->assertEquals(0, $wg->count(), 'All four coroutines have finished execution.');
        });
    }
}
