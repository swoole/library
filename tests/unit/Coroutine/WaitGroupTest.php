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
            $count = 0;
            $N = 4;
            $st = microtime(true);
            foreach (range(1, $N) as $i) {
                \Swoole\Coroutine::create(function () use ($wg, &$count) {
                    System::sleep(0.5);
                    $count++;
                    $wg->done();
                });
            }
            $wg->wait();
            $et = microtime(true);

            $this->assertEquals($count, $N);
            $this->assertLessThan($et - $st, 0.5);
            $this->assertGreaterThan($et - $st, 0.55);
        });
    }
}
