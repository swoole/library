<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

ini_set('swoole.enable_library', 'On');

require_once __DIR__ . '/ext/curl.php';
require_once __DIR__ . '/ext/sockets.php';
require_once __DIR__ . '/ext/standard.php';
// Swoole\MongoDB\Client lives outside the PSR-4 root, so it has to be loaded here to exist under Composer at all.
require_once __DIR__ . '/ext/mongodb.php';
