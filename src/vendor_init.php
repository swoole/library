<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

if (defined('SWOOLE_LIBRARY')) {
    // This is the initialization script for a Composer install of swoole/library, which replaces the
    // extension's embedded copy; it requires swoole.enable_library=Off. With the embedded library on,
    // the constants and global functions below already exist and requiring them again would redeclare
    // them. Loading this file in that state is a configuration error, so fail loudly instead of doing
    // nothing silently.
    throw new \RuntimeException(
        'swoole/library cannot be loaded with swoole.enable_library=On. Run PHP with '
        . '-d swoole.enable_library=Off to use the Composer package, or, with the embedded library on, '
        . 'autoload only the Composer-only namespaces through PSR-4 (see composer-embedded.json).'
    );
}

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
