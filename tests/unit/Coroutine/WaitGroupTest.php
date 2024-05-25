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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(WaitGroup::class)]
class WaitGroupTest extends TestCase
{
    public function testWait()
    {
        run(function () {
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

            self::assertEqualsWithDelta(microtime(true), $st + 0.525, 0.025, 'The four coroutines take about 0.50 to 0.55 second in total to finish.');
            $this->assertEquals(0, $wg->count(), 'All four coroutines have finished execution.');
        });
    }
}
