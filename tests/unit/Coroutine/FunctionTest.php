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

use Swoole\Runtime;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Coroutine\batch
 * @covers \Swoole\Coroutine\go
 * @covers \Swoole\Coroutine\map
 * @covers \Swoole\Coroutine\parallel
 */
class FunctionTest extends TestCase
{
    protected function setUp(): void
    {
        self::trace('TEST START ' . $this->getName());
    }

    protected function tearDown(): void
    {
        self::trace('TEST END ' . $this->getName());
    }

    public function testBatchTimeout(): void
    {
        self::coRun(function () {
            Runtime::setHookFlags(SWOOLE_HOOK_ALL);
            $start = microtime(true);
            self::trace('testBatchTimeout: before batch(), hook=' . Runtime::getHookFlags());
            $results = batch([
                'gethostbyname'     => self::traced('gethostbyname', static fn () => gethostbyname('localhost')),
                'file_get_contents' => self::traced('file_get_contents', static fn () => file_get_contents(__FILE__)),
                'sleep'             => self::traced('sleep', static function () {
                    sleep(1);
                    return true;
                }),
                'usleep' => self::traced('usleep', static function () {
                    usleep(1000);
                    return true;
                }),
            ], 0.1);
            self::trace('testBatchTimeout: after batch(), results=' . json_encode(array_map('get_debug_type', $results)));
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
        self::coRun(function () {
            Runtime::setHookFlags(SWOOLE_HOOK_ALL);
            $start = microtime(true);
            // batch() waits indefinitely by default. Bound the wait so a task that never finishes
            // fails with its name instead of hanging the runner until the step's 5-minute timeout.
            self::trace('testBatch: before batch(), hook=' . Runtime::getHookFlags());
            $results = batch([
                'gethostbyname'     => self::traced('gethostbyname', static fn () => gethostbyname('localhost')),
                'file_get_contents' => self::traced('file_get_contents', static fn () => file_get_contents(__FILE__)),
                'sleep'             => self::traced('sleep', static function () {
                    sleep(1);
                    return true;
                }),
                'usleep' => self::traced('usleep', static function () {
                    usleep(1000);
                    return true;
                }),
            ], 5.0);
            self::trace('testBatch: after batch(), results=' . json_encode(array_map('get_debug_type', $results)));
            Runtime::setHookFlags(0);
            $end = microtime(true);
            $this->assertEquals(count($results), 4);
            $pending = array_keys(array_filter($results, static fn ($value) => $value === null));
            self::assertSame([], $pending, 'Tasks that did not finish: ' . implode(', ', $pending));
            // The lower bound allows for a little clock skew rather than being exactly 1: the batch is timed
            // with microtime() while sleep(1) is served by Swoole's timer, and on a virtualized CI runner the
            // two can disagree by a fraction of a millisecond (0.9996709823608398 was seen in CI). What the
            // bounds are really for is the upper one, which is what fails if the tasks run one after another.
            self::assertThat(
                $end - $start,
                $this->logicalAnd(self::greaterThan(0.99), self::lessThan(1.2)),
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
        self::coRun(function () {
            $cid = go(function () {
                System::sleep(0.001);
            });
            $this->assertTrue(is_int($cid) and $cid > 0);
        });
    }

    public function testParallel(): void
    {
        self::coRun(function () {
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
                // Lower bound just under 0.2: microtime() and Swoole's timer disagree by a fraction of a
                // millisecond, which made `greaterThan(0.2)` fail on values like 0.19958.
                $this->logicalAnd(self::greaterThan(0.19), self::lessThan(0.22)),
                'Four invocations of the callback function should take barely over 0.2 second to finish.'
            );
        });
    }

    public function testMap(): void
    {
        self::coRun(function () {
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

    /**
     * Append one trace line immediately, so a runner killed by the step timeout still leaves a
     * complete record of which task started and which one never finished. The path can be pointed
     * at a CI artifact directory with SWOOLE_TEST_TRACE.
     */
    private static function trace(string $message): void
    {
        $file = getenv('SWOOLE_TEST_TRACE') ?: sys_get_temp_dir() . '/swoole-function-test.trace';
        file_put_contents(
            $file,
            sprintf("%.6f cid=%d %s\n", microtime(true), \Swoole\Coroutine::getCid(), $message),
            FILE_APPEND | LOCK_EX
        );
    }

    private static function traced(string $name, callable $fn): callable
    {
        return static function () use ($name, $fn) {
            self::trace($name . ': start');
            $start = microtime(true);
            try {
                $result = $fn();
                self::trace(sprintf('%s: done %.6fs (%s)', $name, microtime(true) - $start, get_debug_type($result)));
                return $result;
            } catch (\Throwable $e) {
                self::trace($name . ': threw ' . $e::class . ': ' . $e->getMessage());
                throw $e;
            }
        };
    }
}
