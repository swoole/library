<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

/* @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

use Swoole\Coroutine\System;

function swoole_gethostbynamel(string $domain)
{
    return System::getaddrinfo($domain);
}
