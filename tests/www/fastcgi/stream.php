<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

header('Content-Type: text/plain');
for ($i = 0; $i < 4; $i++) {
    usleep(350000);
    echo "part{$i}\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}
