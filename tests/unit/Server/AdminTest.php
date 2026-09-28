<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Server;

use Swoole\Tests\TestCase;

/**
 * What the admin server reads from the operating system, on Linux and on macOS.
 *
 * @internal
 * @covers \Swoole\Server\Admin
 */
class AdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)) {
            self::markTestSkipped('The admin server reads nothing from this operating system.');
        }
    }

    public function testMemorySize(): void
    {
        // No machine that runs the tests has less than that.
        self::assertGreaterThan(256 * 1024 * 1024, self::call('getMemorySize'));
    }

    public function testMemoryOfAProcess(): void
    {
        $own = self::call('getProcessMemoryRealUsage');
        self::assertGreaterThan(1024 * 1024, $own);
        self::assertLessThan(self::call('getMemorySize'), $own);

        self::assertSame($own > 0, self::call('getProcessMemoryRealUsage', getmypid()) > 0);
        self::assertSame(0, self::call('getProcessMemoryRealUsage', self::getUnusedPid()));
    }

    public function testStatusOfAProcess(): void
    {
        $status = self::call('getProcessStatus');
        self::assertMatchesRegularExpression('/^\d+ kB$/', $status['VmRSS']);

        self::assertSame([], self::call('getProcessStatus', self::getUnusedPid()));
    }

    public function testCpuUsageOfAProcess(): void
    {
        [$machine, $process] = self::call('getProcessCpuUsage', getmypid());

        // Something to measure: a quarter of a second of work.
        $until = hrtime(true) + 250_000_000;
        while (hrtime(true) < $until) {
            hash('sha256', random_bytes(1024));
        }

        [$machineAfter, $processAfter] = self::call('getProcessCpuUsage', getmypid());
        self::assertGreaterThan($machine, $machineAfter);
        self::assertGreaterThan($process, $processAfter);
        self::assertLessThanOrEqual($machineAfter - $machine, $processAfter - $process, 'A process cannot use more time than the machine has.');

        self::assertSame([0], self::call('getProcessCpuUsage', self::getUnusedPid()));
    }

    private static function call(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod(Admin::class, $method))->invoke(null, ...$arguments);
    }

    private static function getUnusedPid(): int
    {
        for ($pid = 99_000; $pid > 1; $pid--) {
            if (!posix_kill($pid, 0) && posix_get_last_error() === 3 /* ESRCH */) {
                return $pid;
            }
        }
        self::fail('Every process id is in use.');
    }
}
