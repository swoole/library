<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Thread;

use Swoole\Runtime;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Thread\Pool
 */
class PoolWorkerExitTest extends TestCase
{
    public function testConstructorException(): void
    {
        $this->assertWorkerRecovery('constructor_exception');
    }

    public function testBootstrapException(): void
    {
        $this->assertWorkerRecovery('bootstrap_exception');
    }

    public function testAutoloaderException(): void
    {
        $this->assertWorkerRecovery('autoloader_exception');
    }

    public function testBootstrapExit(): void
    {
        $this->assertWorkerRecovery('bootstrap_exit');
    }

    public function testRunException(): void
    {
        $this->assertWorkerRecovery('run_exception');
    }

    public function testExitZero(): void
    {
        $this->assertWorkerRecovery('exit_zero');
    }

    public function testExitNonzero(): void
    {
        $this->assertWorkerRecovery('exit_nonzero');
    }

    public function testFatalError(): void
    {
        $this->assertWorkerRecovery('fatal_error');
    }

    public function testExistingRunner(): void
    {
        $this->assertWorkerRecovery('exit_nonzero', true);
    }

    public function testWorkerIndexesSurviveRestart(): void
    {
        $this->assertWorkerRecovery('worker_indexes');
    }

    public function testShutdownDoesNotRestartWorker(): void
    {
        $this->assertWorkerRecovery('shutdown', false, 'worker_shutdown.php');
    }

    public function testShutdownDuringStartupDoesNotCreateRemainingWorkers(): void
    {
        $this->assertWorkerRecovery('shutdown_during_startup', false, 'worker_shutdown.php');
    }

    public function testRunnerWithReadOnlyDeployment(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            self::markTestSkipped('Requires Unix directory permissions and an unprivileged user.');
        }
        $this->assertWorkerRecovery('runner_read_only', false, 'worker_runner.php');
    }

    public function testRunnerWithRelativePaths(): void
    {
        $this->assertWorkerRecovery('runner_relative_paths', false, 'worker_runner.php');
    }

    public function testOverlappingPoolsHaveIndependentRunners(): void
    {
        $this->assertWorkerRecovery('runner_overlapping_pools', false, 'worker_runner.php');
    }

    public function testRunnerIsRemovedAfterStartupFailure(): void
    {
        $this->assertWorkerRecovery('runner_startup_failure', false, 'worker_runner.php');
    }

    public function testRunnerCreationFailure(): void
    {
        $this->assertWorkerRecovery('runner_creation_failure', false, 'worker_runner.php');
    }

    private function assertWorkerRecovery(string $mode, bool $legacyRunner = false, string $fixture = 'worker_exit.php'): void
    {
        $directory = sys_get_temp_dir() . '/' . uniqid('swoole_thread_exit_', true);
        mkdir($directory);
        $command = [PHP_BINARY];
        if ($ini = php_ini_loaded_file()) {
            $command[] = '-c';
            $command[] = $ini;
        }
        if ($mode === 'runner_creation_failure') {
            file_put_contents($directory . '/unwritable-temp', 'not a directory');
            $command[] = '-d';
            $command[] = 'sys_temp_dir=' . $directory . '/unwritable-temp';
        }
        // Composer also defines SWOOLE_LIBRARY; select the implementation actually under test.
        $embedded = str_starts_with((new \ReflectionClass(Pool::class))->getFileName(), '@swoole/library/');
        $command  = array_merge($command, [
            '-d', 'swoole.enable_library=' . ($embedded ? 'On' : 'Off'),
            dirname(__DIR__, 2) . '/fixtures/Thread/' . $fixture, $mode, $directory,
            $legacyRunner ? 'legacy' : '',
        ]);
        // Thread tests run serially; use native process functions outside a coroutine.
        $hookFlags = Runtime::getHookFlags();
        Runtime::setHookFlags($hookFlags & ~SWOOLE_HOOK_PROC);
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $directory . '/output.log', 'w'],
            2 => ['redirect', 1],
        ], $pipes);

        try {
            self::assertIsResource($process);
            fclose($pipes[0]);
            // Pool::start() blocks the OS thread, so a coroutine timer cannot guard this regression.
            $deadline = microtime(true) + 5;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);

            $output = (string) file_get_contents($directory . '/output.log');
            self::assertFalse($status['running'], "Pool did not recover from {$mode} within 5 seconds.\n{$output}");
            self::assertSame(0, $status['exitcode'], $output);
            self::assertStringContainsString('RECOVERED', $output);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            Runtime::setHookFlags($hookFlags);
            chmod($directory, 0700);
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
