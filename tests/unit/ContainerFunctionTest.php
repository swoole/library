<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole;

use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers ::swoole_container_cpu_num
 * @covers ::swoole_is_in_container
 */
class ContainerFunctionTest extends TestCase
{
    /**
     * @dataProvider containerFiles
     */
    public function testContainerDetectionAndCpuQuota(array $files, bool $container, ?int $cpus = null, string $override = ''): void
    {
        $output   = '';
        $errors   = '';
        $exitCode = -1;
        self::coRun(static function () use ($files, $override, &$output, &$errors, &$exitCode): void {
            $process = proc_open([PHP_BINARY, '-d', 'swoole.enable_library=Off', dirname(__DIR__) . '/fixtures/container-functions.php'], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            fwrite($pipes[0], json_encode(['files' => $files, 'override' => $override], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        });
        self::assertSame(0, $exitCode, $errors . $output);
        self::assertSame('', $errors);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($container, $result['container']);
        self::assertSame($cpus ?? $result['host_cpus'], $result['cpus']);
    }

    public static function containerFiles(): iterable
    {
        yield 'missing procfs' => [[], false];
        yield 'no container' => [['/proc/self/mountinfo' => 'ordinary mount'], false];
        yield 'docker at zero' => [['/proc/self/mountinfo' => 'docker mount'], true];
        yield 'kubepods at zero' => [['/proc/self/mountinfo' => 'kubepods mount'], true];
        yield 'marker later' => [['/proc/self/mountinfo' => 'mount /docker/overlay'], true];
        yield 'environment override' => [[], false, 3, '3'];
        foreach ([
            '200000 100000'       => 2,
            "  250000   100000\n" => 2,
            "300000\t100000"      => 3,
            'max 100000'          => null,
            '100000 0'            => null,
            '100000 -1'           => null,
            '100000'              => null,
            'invalid 100000'      => null,
            '100000 invalid'      => null,
            ''                    => null,
            '1e999 100000'        => null,
        ] as $quota => $expected) {
            yield 'v2: ' . $quota => [[
                '/proc/self/mountinfo'   => 'docker mount',
                '/sys/fs/cgroup/cpu.max' => (string) $quota,
            ], true, $expected];
        }
        yield 'v1 quota' => [[
            '/proc/self/mountinfo'                          => 'docker mount',
            '/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_quota_us'   => '200000',
            '/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_period_us'  => '100000',
        ], true, 2];
        yield 'v1 unlimited' => [[
            '/proc/self/mountinfo'                          => 'docker mount',
            '/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_quota_us'   => '-1',
            '/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_period_us'  => '100000',
        ], true];
        yield 'v1 invalid period' => [[
            '/proc/self/mountinfo'                          => 'docker mount',
            '/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_quota_us'   => '200000',
            '/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_period_us'  => '0',
        ], true];
    }
}
