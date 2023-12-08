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
use Swoole\Runtime;

/**
 * @internal
 * @coversNothing
 */
class FunctionTest extends TestCase
{
    public function testBatchTimeout()
    {
        run(function () {
            Runtime::setHookFlags(SWOOLE_HOOK_ALL);
            $start   = microtime(true);
            $results = batch([
                'gethostbyname' => fn() => gethostbyname('localhost'),
                'file_get_contents' => fn() => file_get_contents(__FILE__),
                'sleep' => function () {
                    sleep(1);
                    return true;
                },
                'usleep' => function () {
                    usleep(1000);
                    return true;
                },
            ], 0.1);
            Runtime::setHookFlags(0);
            self::assertEqualsWithDelta(microtime(true), $start + 0.11, 0.01, 'Tasks in the batch take about 0.10 to 0.12 second in total to finish.');
            $this->assertEquals(count($results), 4);

            $this->assertEquals($results['gethostbyname'], gethostbyname('localhost'));
            $this->assertEquals($results['file_get_contents'], file_get_contents(__FILE__));
            $this->assertEquals($results['sleep'], null);
            $this->assertTrue($results['usleep']);
        });
    }

    public function testBatch()
    {
        run(function () {
            Runtime::setHookFlags(SWOOLE_HOOK_ALL);
            $start   = microtime(true);
            $results = batch([
                'gethostbyname' => fn() => gethostbyname('localhost'),
                'file_get_contents' => fn() => file_get_contents(__FILE__),
                'sleep' => function () {
                    sleep(1);
                    return true;
                },
                'usleep' => function () {
                    usleep(1000);
                    return true;
                },
            ]);
            Runtime::setHookFlags(0);
            $end = microtime(true);
            $this->assertEquals(count($results), 4);
            $this->assertGreaterThan(1, $end - $start);
            $this->assertLessThan(1.2, $end - $start);

            $this->assertEquals($results['gethostbyname'], gethostbyname('localhost'));
            $this->assertEquals($results['file_get_contents'], file_get_contents(__FILE__));
            $this->assertTrue($results['sleep']);
            $this->assertTrue($results['usleep']);
        });
    }

    public function testGo()
    {
        run(function () {
            $cid = go(function () {
                System::sleep(0.001);
            });
            $this->assertTrue(is_int($cid) and $cid > 0);
        });
    }

    public function testParallel()
    {
        run(function () {
            $start   = microtime(true);
            $c       = 4;
            $results = [];
            parallel($c, function () use (&$results) {
                System::sleep(0.2);
                $results[] = System::gethostbyname('localhost');
            });
            $end = microtime(true);

            $this->assertEquals(count($results), $c);
            $this->assertGreaterThan(0.2, $end - $start);
            $this->assertLessThan(0.22, $end - $start);
        });
    }

    public function testMap()
    {
        run(function () {
            $start   = microtime(true);
            $list    = [1, 2, 3, 4];
            $results = map($list, function (int $i): int {
                System::sleep(0.2);
                return $i * 2;
            });
            self::assertEqualsWithDelta(microtime(true), $start + 0.21, 0.05, 'The method call to map() takes about 0.20 to 0.22 second in total to finish.');
            $this->assertSameSize($results, $list);
            $this->assertSame([2, 4, 6, 8], $results);
        });
    }
}
