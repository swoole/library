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
            $start = microtime(true);
            $results = batch([
                'gethostbyname' => function () {
                    return gethostbyname('localhost');
                },
                'file_get_contents' => function () {
                    return file_get_contents(__FILE__);
                },
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
            $end = microtime(true);
            $this->assertEquals(count($results), 4);
            $this->assertGreaterThan(0.1, $end - $start);
            $this->assertLessThan(0.12, $end - $start);

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
            $start = microtime(true);
            $results = batch([
                'gethostbyname' => function () {
                    return gethostbyname('localhost');
                },
                'file_get_contents' => function () {
                    return file_get_contents(__FILE__);
                },
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

    public function testParallel()
    {
        run(function () {
            $start = microtime(true);
            $c = 4;
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
}
