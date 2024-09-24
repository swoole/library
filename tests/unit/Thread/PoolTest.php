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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swoole\Tests\TestThread;

/**
 * @internal
 */
#[CoversClass(Pool::class)]
class PoolTest extends TestCase
{
    public function testPool(): void
    {
        $map = new Map();

        (new Pool(TestThread::class, 4))
            ->withClassDefinitionFile(dirname(__DIR__, 2) . '/TestThread.php')
            ->withArguments([uniqid(), $map])
            ->start()
        ;

        $this->assertEquals($map['sleep'], 65);
        $this->assertEquals($map['thread'], 13);
    }
}
