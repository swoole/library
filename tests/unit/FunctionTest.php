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

use PHPUnit\Framework\TestCase;

class FunctionTest extends TestCase
{
    /**
     * @covers ::swoole_library_get_options
     * @covers ::swoole_library_set_options
     */
    function testOptions()
    {
        $options = [__METHOD__ => uniqid()];
        swoole_library_set_options($options);
        $this->assertEquals($options, swoole_library_get_options());
    }

    /**
     * @covers ::swoole_library_get_option
     * @covers ::swoole_library_set_option
     */
    function testOption()
    {
        $option = uniqid();
        swoole_library_set_option(__METHOD__, $option);
        $this->assertEquals($option, swoole_library_get_option(__METHOD__));
    }
}
