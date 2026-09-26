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

// Every library file outside the PSR-4 root (src/core), which Composer cannot autoload on demand. The list follows the
// order of src/__init__.php, and a file of that kind added there has to be added here as well.
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/std/exec.php';
require_once __DIR__ . '/core/Coroutine/Http/functions.php';
require_once __DIR__ . '/core/Coroutine/functions.php';
require_once __DIR__ . '/ext/curl.php';
require_once __DIR__ . '/ext/sockets.php';
require_once __DIR__ . '/ext/standard.php';
require_once __DIR__ . '/ext/mongodb.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/alias.php';
require_once __DIR__ . '/alias_ns.php';
