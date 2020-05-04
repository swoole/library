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
header('Status:  402  Payment Required  '); // HTTP status overridden with reason phase and extra spaces included.

echo "Hello world!\n";
