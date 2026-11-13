<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Kafka;

use Swoole\Coroutine;
use Swoole\Kafka\Trace;
use Swoole\Tests\TestCase;

/**
 * Serial: SWOOLE_KAFKA_TRACE is process-wide, and the Kafka client reads it from every request.
 *
 * @internal
 * @covers \Swoole\Kafka\Trace
 */
class TraceTest extends TestCase
{
    public function testLineIsFlushedAndTracingStaysOffWhenUnset(): void
    {
        $previous = getenv('SWOOLE_KAFKA_TRACE');
        $path     = sys_get_temp_dir() . '/swoole-kafka-trace-test-' . bin2hex(random_bytes(4));
        try {
            putenv('SWOOLE_KAFKA_TRACE=' . $path);
            Trace::reset();
            Trace::log('marker');
            $this->assertFileExists($path);
            $line = (string) file_get_contents($path);
            $this->assertMatchesRegularExpression('/^\d+\.\d{6} cid=-?\d+ marker\n$/', $line);

            putenv('SWOOLE_KAFKA_TRACE');
            Trace::reset();
            Trace::log('silent');
            $this->assertSame($line, (string) file_get_contents($path));
        } finally {
            Trace::reset();
            if ($previous === false) {
                putenv('SWOOLE_KAFKA_TRACE');
            } else {
                putenv('SWOOLE_KAFKA_TRACE=' . $previous);
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testConcurrentWritesDoNotRaiseALockWarning(): void
    {
        $previous = getenv('SWOOLE_KAFKA_TRACE');
        $path     = sys_get_temp_dir() . '/swoole-kafka-trace-test-' . bin2hex(random_bytes(4));
        try {
            putenv('SWOOLE_KAFKA_TRACE=' . $path);
            Trace::reset();
            Coroutine\run(static function (): void {
                Coroutine::create(static function (): void {
                    for ($i = 0; $i < 40; $i++) {
                        Trace::log('left ' . $i);
                    }
                });
                for ($i = 0; $i < 40; $i++) {
                    Trace::log('right ' . $i);
                }
            });
            $contents = (string) file_get_contents($path);
            $this->assertStringContainsString('left 39', $contents);
            $this->assertStringContainsString('right 39', $contents);
            $this->assertStringNotContainsString('Exclusive locks', $contents);
        } finally {
            Trace::reset();
            if ($previous === false) {
                putenv('SWOOLE_KAFKA_TRACE');
            } else {
                putenv('SWOOLE_KAFKA_TRACE=' . $previous);
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
