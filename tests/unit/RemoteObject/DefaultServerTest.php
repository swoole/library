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
 * The start of the default remote object server, swoole_init_default_remote_object_server(). Every test gets a
 * directory, and thus a server, of its own.
 *
 * @internal
 * @covers ::swoole_init_default_remote_object_server
 */
class DefaultServerTest extends TestCase
{
    private array $options = [];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = swoole_library_get_options();
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            swoole_tests_stop_remote_object_server($this->dir);
            @unlink($this->dir . '/bootstrap.php');
            @rmdir($this->dir);
        }
        swoole_library_set_options($this->options);
        parent::tearDown();
    }

    public function testDefaultKeyIsPrivateStableAndRequired(): void
    {
        $this->useDirectory(sys_get_temp_dir() . '/swoole-ro-key-' . uniqid());
        self::coRun(static function (): void { swoole_init_default_remote_object_server(); });
        $key = swoole_get_default_remote_object_api_key();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $key);
        self::assertSame($key, swoole_get_default_remote_object_api_key());
        foreach (['key', 'php'] as $extension) {
            self::assertSame(0600, fileperms($this->dir . '/remote-object-server.' . $extension) & 0777);
        }
        self::coRun(function (): void {
            self::assertFalse((new Client('unix://' . $this->dir . '/remote-object-server.sock'))->ping());
            self::assertFalse((new Client('unix://' . $this->dir . '/remote-object-server.sock', 0, ['api_key' => 'wrong']))->ping());
        });
        self::assertTrue($this->ping());
    }

    public function testUnsafeKeyPermissionsAreRejected(): void
    {
        $this->useDirectory(sys_get_temp_dir() . '/swoole-ro-key-mode-' . uniqid());
        swoole_get_default_remote_object_api_key();
        chmod($this->dir . '/remote-object-server.key', 0644);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('private file');
        swoole_get_default_remote_object_api_key();
    }

    public function testRemoteObjectDestructorContainsNonRpcFailures(): void
    {
        $this->useDirectory(sys_get_temp_dir() . '/swoole-ro-destructor-' . uniqid());
        $client = new class extends Client {
            public int $releases = 0;

            public function __construct()
            {
            }

            public function release(int $objectId): void
            {
                $this->releases++;
                throw new \LogicException('expected destructor failure');
            }
        };
        $object = \Swoole\RemoteObject::marshal(1, 1, 'fixture');
        (new \ReflectionProperty(\Swoole\RemoteObject::class, 'client'))->setValue($object, $client);
        $log      = $this->dir . '/destructor.log';
        $previous = ini_set('error_log', $log);
        try {
            unset($object);
            self::assertSame(1, $client->releases);
            self::assertStringContainsString('expected destructor failure', file_get_contents($log));
        } finally {
            ini_set('error_log', $previous);
            @unlink($log);
        }
    }

    /**
     * The directory goes into a shell command and into the PHP file the server runs. A space or a quote in it
     * used to break the command or the file, and the server did not start.
     */
    public function testStartInADirectoryWithASpaceAndAQuote(): void
    {
        $this->useDirectory(sys_get_temp_dir() . "/swoole ro'test " . uniqid());

        self::coRun(static function () {
            swoole_init_default_remote_object_server();
        });

        self::assertTrue($this->ping());
    }

    /**
     * escapeshellarg() drops the bytes that are not valid in the current locale, so with the "C" locale a non-ASCII
     * character went missing from the directory in the shell command, and the server did not start.
     */
    public function testStartInADirectoryWithANonAsciiCharacterUnderTheCLocale(): void
    {
        $this->useDirectory(sys_get_temp_dir() . '/swoole-ro-测试-' . uniqid());

        $locale = setlocale(LC_CTYPE, '0');
        setlocale(LC_CTYPE, 'C');
        try {
            self::coRun(static function () {
                swoole_init_default_remote_object_server();
            });
        } finally {
            setlocale(LC_CTYPE, $locale);
        }

        self::assertTrue($this->ping());
    }

    public function testStartOutsideACoroutine(): void
    {
        $this->useDirectory(sys_get_temp_dir() . '/swoole-ro-sync-' . uniqid());
        $hooks = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::setHookFlags(0);
        try {
            swoole_init_default_remote_object_server();
        } finally {
            \Swoole\Runtime::setHookFlags($hooks);
        }
        self::assertTrue($this->ping());
    }

    /**
     * @dataProvider failedStarts
     */
    public function testFailedStartIsBoundedAndReleasesTheLock(bool $coroutine, string $bootstrap, string $message): void
    {
        $this->useDirectory(sys_get_temp_dir() . '/swoole-ro-fail-' . uniqid());
        file_put_contents($this->dir . '/bootstrap.php', "<?php\n" . $bootstrap);
        swoole_library_set_option('default_remote_object_server_startup_timeout', 0.5);

        $exception = null;
        $start     = static function () use (&$exception): void {
            try {
                swoole_init_default_remote_object_server();
            } catch (\RuntimeException $e) {
                $exception = $e;
            }
        };
        if ($coroutine) {
            self::coRun($start);
        } else {
            $hooks = \Swoole\Runtime::getHookFlags();
            \Swoole\Runtime::setHookFlags(0);
            try {
                $start();
            } finally {
                \Swoole\Runtime::setHookFlags($hooks);
            }
        }
        self::assertNotNull($exception, 'A failed startup must throw');
        self::assertStringContainsString($message, $exception->getMessage());

        $lock = fopen($this->dir . '/remote-object-server.lock', 'c');
        try {
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'Startup failures must release the lock');
        } finally {
            fclose($lock);
        }
    }

    public static function failedStarts(): iterable
    {
        foreach ([false, true] as $coroutine) {
            $mode = $coroutine ? 'coroutine: ' : 'sync: ';
            yield $mode . 'nonzero exit' => [$coroutine, "fwrite(STDERR, str_repeat('failure', 20000)); exit(7);", 'exit code 7'];
            yield $mode . 'signal exit' => [$coroutine, 'posix_kill(getmypid(), SIGTERM);', 'signal 15'];
            yield $mode . 'no daemon' => [$coroutine, 'exit(0);', 'timed out waiting'];
            yield $mode . 'frontend hangs' => [$coroutine, 'sleep(30);', 'timed out waiting'];
            yield $mode . 'dead daemon' => [$coroutine, "file_put_contents(__DIR__ . '/remote-object-server.pid', (string) getmypid()); exit(0);", 'exited before becoming ready'];
        }
    }

    public function testLockContentionHasAStartupTimeout(): void
    {
        $this->useDirectory(sys_get_temp_dir() . '/swoole-ro-lock-' . uniqid());
        swoole_library_set_option('default_remote_object_server_startup_timeout', 0.05);
        $lock = fopen($this->dir . '/remote-object-server.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('timed out waiting');
            swoole_init_default_remote_object_server();
        } finally {
            fclose($lock);
        }
    }

    public function testBoundSocketWithoutAListenerIsNotReady(): void
    {
        $this->useDirectory(sys_get_temp_dir() . '/swoole-ro-bind-' . uniqid());
        swoole_library_set_option('default_remote_object_server_startup_timeout', 0.05);
        $hooks = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::setHookFlags(0);
        $socket = stream_socket_server('unix://' . $this->dir . '/remote-object-server.sock', $errno, $error, STREAM_SERVER_BIND);
        $lock   = fopen($this->dir . '/remote-object-server.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('timed out waiting');
            swoole_init_default_remote_object_server();
        } finally {
            fclose($socket);
            fclose($lock);
            \Swoole\Runtime::setHookFlags($hooks);
        }
    }

    public function testRunningPidWithoutASocketHasAStartupTimeout(): void
    {
        $this->useDirectory(sys_get_temp_dir() . '/swoole-ro-pid-' . uniqid());
        file_put_contents($this->dir . '/remote-object-server.pid', (string) getmypid());
        swoole_library_set_option('default_remote_object_server_startup_timeout', 0.05);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('timed out waiting');
        swoole_init_default_remote_object_server();
    }

    private function useDirectory(string $dir): void
    {
        $this->dir = $dir;
        mkdir($this->dir, 0700);
        swoole_library_set_option('default_remote_object_server_dir', $this->dir);
        swoole_library_set_option('default_remote_object_server_worker_num', 1);

        // The file the server loads before it goes into the background. It has to bring the library, as the
        // server is a process of its own, and may run with the library of the extension turned off.
        $autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        file_put_contents($this->dir . '/bootstrap.php', "<?php\n\nif (!defined('SWOOLE_LIBRARY')) {\n    require {$autoload};\n}\n");
    }

    private function ping(): bool
    {
        $result = false;
        self::coRun(function () use (&$result) {
            $result = (new Client('unix://' . $this->dir . '/remote-object-server.sock', 0, ['api_key' => swoole_get_default_remote_object_api_key()]))->ping();
        });
        return $result;
    }
}
