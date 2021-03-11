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
class WaitGroupTest extends TestCase
{
    public function testWait()
    {
        run(function () {
            $wg = new WaitGroup(4);
            $N = 4;
            $st = microtime(true);
            foreach (range(1, $N) as $i) {
                \Swoole\Coroutine::create(function () use ($wg) {
                    System::sleep(0.5);
                    $wg->done();
                });
            }
            $this->assertEquals($N, $wg->count(), 'Four active coroutines in sleeping state (not yet finished execution).');
            $wg->wait();
            $this->assertEquals(0, $wg->count(), 'All four coroutines have finished execution.');

            $et = microtime(true);
            $this->assertGreaterThan(0.5, $et - $st, 'The four coroutines take more than 0.5 second in total to execute.');
            $this->assertLessThan(0.55, $et - $st, 'The four coroutines take less than 0.55 second in total to execute.');
        });
    }
}
