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

use Swoole\Tests\TestCase;
use Swoole\Tests\TestThread;

/**
 * @internal
 * @covers \Swoole\Thread\Pool
 */
class PoolTest extends TestCase
{
    public function testPool(): void
    {
        $map = new Map();

        (new Pool(TestThread::class, 4))
            ->withClassDefinitionFile(dirname(__DIR__, 2) . '/TestThread.php')
            ->withArguments(uniqid(), $map)
            ->start()
        ;

        // Shutdown can race with completion notices from other workers; the exact restart count varies.
        self::assertGreaterThan(50, $map['sleep']);
        self::assertSame($map['thread'] * 5, $map['sleep']);
    }

    /**
     * A pool that is not given the file of the class looks the file up, and takes it only when loading it runs
     * nothing, as every thread loads it.
     */
    public function testFileOfTheClass(): void
    {
        $pool = new class(TestThread::class, 1) extends Pool {
            public function check(string $file): bool
            {
                return $this->isValidPhpFile($file);
            }
        };

        self::assertTrue($pool->check(dirname(__DIR__, 2) . '/TestThread.php'));

        $file = sys_get_temp_dir() . '/' . uniqid('swoole_thread_pool_test_', true) . '.php';
        try {
            file_put_contents($file, "<?php\n\nclass A {}\n\necho 'loaded';\n");
            self::assertFalse($pool->check($file), 'The file does something when it is loaded.');

            file_put_contents($file, "<?php\n\nclass A {\n");
            self::assertFalse($pool->check($file), 'The file cannot be parsed.');
        } finally {
            @unlink($file);
        }
    }
}
