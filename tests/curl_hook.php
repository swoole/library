<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

/*
 * Works around a defect of Swoole 6.2 that keeps tests/bootstrap.php from swapping SWOOLE_HOOK_NATIVE_CURL for
 * SWOOLE_HOOK_CURL: once the native hook has been on, the curl functions hooked through SWOOLE_HOOK_CURL fail with
 * a Swoole\Exception, "curl_init func not exists". The extension looks up the library function that stands in for
 * a curl function (swoole_curl_init() for curl_init(), and so on) only the first time that curl function is hooked
 * at all, and the native hook, which needs no such function, is what hooks it first. Swoole 6.3 looks the function
 * up whenever it is missing.
 *
 * Under counit the native hook always comes first: the counit script starts the scheduler with SWOOLE_HOOK_ALL,
 * and PHPUnit loads tests/bootstrap.php inside of it. This file is loaded by the Composer autoloader ("files" in
 * composer.json and composer-embedded.json), i.e. before the scheduler starts. It turns SWOOLE_HOOK_CURL on, which
 * makes the extension do the lookup, and puts the hook flags back as they were.
 */
(static function (): void {
    if (!extension_loaded('swoole') || !function_exists('swoole_curl_init')) {
        return;
    }

    $flags = Swoole\Runtime::getHookFlags();
    if ($flags & (SWOOLE_HOOK_CURL | SWOOLE_HOOK_NATIVE_CURL)) {
        return; // Either it is done already, or it is too late.
    }

    Swoole\Runtime::setHookFlags($flags | SWOOLE_HOOK_CURL);
    Swoole\Runtime::setHookFlags($flags);
})();
