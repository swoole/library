<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

if (SWOOLE_USE_SHORTNAME) {
    class_alias(Swoole\Coroutine\WaitGroup::class, Co\WaitGroup::class, true);
    class_alias(Swoole\Coroutine\Server::class, Co\Server::class, true);
    class_alias(Swoole\Coroutine\FastCGI\Client::class, Co\FastCGI\Client::class, true);
}
