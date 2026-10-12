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
        $embedded = str_starts_with((new \ReflectionClass(Registration::class))->getFileName(), '@swoole/');
        $process  = proc_open([PHP_BINARY, '-d', 'swoole.enable_library=' . ($embedded ? 'On' : 'Off'),
            dirname(__DIR__, 2) . '/fixtures/name_resolver_server.php', $backend, (string) $mode,
            REDIS_SERVER_URL, CONSUL_AGENT_URL, NACOS_SERVER_URL,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors . $output);
        self::assertSame('DONE', trim($output), $errors);
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
