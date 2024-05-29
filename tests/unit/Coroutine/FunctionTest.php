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

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;
use Swoole\Runtime;

/**
 * @internal
 * @coversNothing
 */
#[CoversFunction('Swoole\Coroutine\batch')]
#[CoversFunction('Swoole\Coroutine\go')]
#[CoversFunction('Swoole\Coroutine\parallel')]
#[CoversFunction('Swoole\Coroutine\map')]
class FunctionTest extends TestCase
{
    public function testBatchTimeout(): void
    {
        run(function () {
            Runtime::setHookFlags(SWOOLE_HOOK_ALL);
            $start   = microtime(true);
            $results = batch([
                'gethostbyname'     => fn () => gethostbyname('localhost'),
                'file_get_contents' => fn () => file_get_contents(__FILE__),
                'sleep'             => function () {
                    sleep(1);
                    return true;
                },
                'usleep' => function () {
                    usleep(1000);
                    return true;
                },
            ], 0.1);
            Runtime::setHookFlags(0);
            self::assertThat(
                microtime(true),
                self::logicalAnd(self::greaterThan($start + 0.09), self::lessThan($start + 0.15)),
                'Tasks in the batch take 0.10+ second to finish.'
            );
            $this->assertEquals(count($results), 4);

            $this->assertEquals($results['gethostbyname'], gethostbyname('localhost'));
            $this->assertEquals($results['file_get_contents'], file_get_contents(__FILE__));
            $this->assertEquals($results['sleep'], null);
            $this->assertTrue($results['usleep']);
        });
    }

    public function testBatch(): void
    {
        run(function () {
            Runtime::setHookFlags(SWOOLE_HOOK_ALL);
            $start   = microtime(true);
            $results = batch([
                'gethostbyname'     => fn () => gethostbyname('localhost'),
                'file_get_contents' => fn () => file_get_contents(__FILE__),
                'sleep'             => function () {
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
            self::assertThat(
                $end - $start,
                $this->logicalAnd(self::greaterThan(1), self::lessThan(1.2)),
                'Those batch tasks should take barely over a second to finish.'
            );

            $this->assertEquals($results['gethostbyname'], gethostbyname('localhost'));
            $this->assertEquals($results['file_get_contents'], file_get_contents(__FILE__));
            $this->assertTrue($results['sleep']);
            $this->assertTrue($results['usleep']);
        });
    }

    public function testGo(): void
    {
        run(function () {
            $cid = go(function () {
                System::sleep(0.001);
            });
            $this->assertTrue(is_int($cid) and $cid > 0);
        });
    }

    public function testParallel(): void
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
            self::assertThat(
                $end - $start,
                $this->logicalAnd(self::greaterThan(0.2), self::lessThan(0.22)),
                'Four invocations of the callback function should take barely over 0.2 second to finish.'
            );
        });
    }

    public function testMap(): void
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
