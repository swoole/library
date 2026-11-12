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
 * @covers ::swoole_exec
 */
class ExecTest extends TestCase
{
    public function testExecKeepsTrailingSpaces(): void
    {
        self::coRun(function (): void {
            $output = null;
            $last   = swoole_exec("printf 'a \nb  \n'", $output);

            self::assertSame('b  ', $last);
            self::assertSame(['a ', 'b  '], $output);
        });
    }

    public function testExecStripsOnlyTheCarriageReturnLeftByExplode(): void
    {
        self::coRun(function (): void {
            $output = null;
            $last   = swoole_exec("printf 'a \r\n'", $output);

            self::assertSame('a ', $last);
            self::assertSame(['a '], $output);
        });
    }
}
