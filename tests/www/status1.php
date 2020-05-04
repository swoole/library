<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

header('HTTP/1.0 200 OK');
header('Status: 401'); // HTTP status overridden without reason phase included.

echo "Hello world!\n";
