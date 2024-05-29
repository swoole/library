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

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
#[CoversFunction('swoole_library_get_options')]
#[CoversFunction('swoole_library_set_options')]
class FunctionTest extends TestCase
{
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
