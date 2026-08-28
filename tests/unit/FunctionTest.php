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
 * @covers ::swoole_library_get_options
 * @covers ::swoole_library_set_options
 */
class FunctionTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $options = [];

    /**
     * These tests overwrite the library options, and swoole_library_set_options() replaces them wholesale.
     * SwooleLibrary::$options is static and the suite runs in a single process, so without restoring them the
     * options set up in tests/bootstrap.php are lost for every test that runs afterwards. RemoteObjectTest is
     * the one that notices: it needs 'default_remote_object_server_dir' to find its bootstrap file.
     */
    protected function setUp(): void
    {
        $this->options = swoole_library_get_options();
    }

    protected function tearDown(): void
    {
        swoole_library_set_options($this->options);
    }

    public function testOptions(): void
    {
        $options = [__METHOD__ => uniqid()];
        swoole_library_set_options($options);
        $this->assertEquals($options, swoole_library_get_options());
    }

    public function testOption(): void
    {
        $option = uniqid();
        swoole_library_set_option(__METHOD__, $option);
        $this->assertEquals($option, swoole_library_get_option(__METHOD__));
    }
}
