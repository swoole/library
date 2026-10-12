<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\NameResolver;

use Swoole\Tests\TestCase;
use Swoole\Timer;

/**
 * @internal
 * @coversNothing
 */
class ServerLifecycleTest extends TestCase
{
    /** @dataProvider backends */
    public function testAutomaticRegistrationAndFinalDeregistration(string $backend, int $mode): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('This test starts process workers');
        }
        self::coRun(function () use ($backend, $mode): void {
            $embedded = str_starts_with((new \ReflectionClass(Registration::class))->getFileName(), '@swoole/');
            // A file for stderr cannot fill a pipe while stdout is being read.
            $stderr = tmpfile();
            self::assertIsResource($stderr);
            $process = proc_open([PHP_BINARY, '-d', 'swoole.enable_library=' . ($embedded ? 'On' : 'Off'),
                dirname(__DIR__, 2) . '/fixtures/name_resolver_server.php', $backend, (string) $mode,
                REDIS_SERVER_URL, CONSUL_AGENT_URL, NACOS_SERVER_URL,
            ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderr], $pipes);
            self::assertIsResource($process);
            $pid      = proc_get_status($process)['pid'];
            $timedOut = false;
            $timer    = Timer::after(20000, static function () use ($process, $pid, &$timedOut): void {
                $timedOut = true;
                // The fixture owns its session; kill workers too, so none can keep stdout open.
                if (!posix_kill(-$pid, SIGKILL)) {
                    proc_terminate($process, SIGKILL);
                }
            });
            try {
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $status = proc_close($process);
                rewind($stderr);
                $errors = stream_get_contents($stderr);
                self::assertFalse($timedOut, "Lifecycle test timed out ({$backend}/{$mode}):\n" . $errors . $output);
                self::assertSame(0, $status, $errors . $output);
                self::assertSame('DONE', trim($output), $errors);
            } finally {
                if (Timer::exists($timer)) {
                    Timer::clear($timer);
                }
                if (is_resource($process)) {
                    posix_kill(-$pid, SIGKILL);
                    proc_terminate($process, SIGKILL);
                    proc_close($process);
                }
                fclose($stderr);
            }
        });
    }

    public static function backends(): array
    {
        $cases = [];
        foreach (['redis', 'consul', 'nacos'] as $backend) {
            foreach ([SWOOLE_BASE, SWOOLE_PROCESS] as $mode) {
                $cases[] = [$backend, $mode];
            }
        }
        return $cases;
    }
}
