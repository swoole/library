<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\RemoteObject;

use Swoole\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class BootstrapIsolationTest extends TestCase
{
    public function testAnotherProcessCannotRemoveTheDefaultServersSocket(): void
    {
        self::coRun(function (): void {
            $client = swoole_get_default_remote_object_client();
            $dir    = swoole_get_default_remote_object_server_dir();
            self::assertSame(5, $client->call('strlen', 'alive'));
            $embedded = str_starts_with((new \ReflectionFunction('swoole_init_default_remote_object_server'))->getFileName(), '@swoole/');
            $process  = proc_open([PHP_BINARY, '-d', 'swoole.enable_library=' . ($embedded ? 'On' : 'Off'),
                '-r', 'require $argv[1]; echo swoole_get_default_remote_object_server_dir();',
                dirname(__DIR__, 2) . '/bootstrap.php',
            ], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            self::assertSame(0, proc_close($process), $output);
            self::assertNotSame($dir, $output);
            self::assertFileExists($dir . '/remote-object-server.sock');
            self::assertSame(5, $client->call('strlen', 'alive'));
        });
    }
}
