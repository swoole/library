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

    private function useDirectory(string $dir): void
    {
        $this->dir = $dir;
        mkdir($this->dir);
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
            $result = (new Client('unix://' . $this->dir . '/remote-object-server.sock'))->ping();
        });
        return $result;
    }
}
