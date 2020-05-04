<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

header('X-Foo0: Bar0');    // Set a standard HTTP header.
header('X-Foo1:Bar1');     // Set an HTTP header without space after the colon.
header('X-Foo2 Bar2');     // Set an invalid HTTP header (without colon included).
header('X-Foo3:Bar3 ');    // Set an HTTP header with extra space(s) after.
header(' X-Foo4:Bar4');    // Set an HTTP header with extra space(s) at the beginning.
header(' X-Foo5:  Bar5 '); // Set an HTTP header with extra space(s) before, after, and in-between.

echo "Hello world!\n";
