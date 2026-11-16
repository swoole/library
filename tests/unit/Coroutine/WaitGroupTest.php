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
            $N        = 4;
            $wg       = new WaitGroup($N);
            $ready    = new Channel($N);
            $release  = new Channel($N);
            $finished = new Channel($N);
            $observed = new Channel(1);
            $returned = false;
            foreach (range(1, $N) as $i) {
                \Swoole\Coroutine::create(static function () use ($wg, $ready, $release, $finished): void {
                    $ready->push(true);
                    $release->pop();
                    $wg->done();
                    $finished->push(true);
                });
            }
            foreach (range(1, $N) as $i) {
                self::assertTrue($ready->pop());
            }
            self::assertSame($N, $wg->count());
            \Swoole\Coroutine::create(static function () use ($wg, $release, $finished, $observed, $N, &$returned): void {
                \Swoole\Coroutine::sleep(0.001);
                for ($i = 1; $i < $N; $i++) {
                    $release->push(true);
                    $finished->pop();
                }
                // The last child is still suspended: wait() must not have returned after the other three finished.
                $observed->push([!$returned, $wg->count()]);
                $release->push(true);
            });
            self::assertTrue($wg->wait());
            $returned = true;
            self::assertSame([true, 1], $observed->pop());
            self::assertSame(0, $wg->count(), 'All four coroutines have finished execution.');
        });
    }
}
