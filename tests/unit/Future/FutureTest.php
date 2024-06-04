<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Future;

use PHPUnit\Framework\TestCase;
use function Co\run;

/**
 * @internal
 * @coversNothing
 */
class FutureTest extends TestCase
{
    public function testAwait(): void
    {
        run(function (): void {
            $future = async(static function (): string {
                return 'test';
            });

            $this->assertSame('test', $future->await());
        });
    }

    public function testJoin(): void
    {
        run(function (): void {
            $future1 = async(static function (): string {
                return 'foo';
            });

            $future2 = async(static function (): string {
                return 'bar';
            });

            $strings = join($future1, $future2);

            $this->assertContains('foo', $strings);
            $this->assertContains('bar', $strings);
        });
    }
}
