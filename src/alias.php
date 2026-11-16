<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace {
    if (SWOOLE_USE_SHORTNAME) {
        class_alias(Swoole\Coroutine\WaitGroup::class, Co\WaitGroup::class, true);
        class_alias(Swoole\Coroutine\Server::class, Co\Server::class, true);
        class_alias(Swoole\Coroutine\Server\Connection::class, Co\Server\Connection::class, true);
    }

    class_alias(Swoole\Process\Manager::class, Swoole\Process\ProcessManager::class, true);
}

namespace Co {
    use Swoole\Coroutine;

    if (SWOOLE_USE_SHORTNAME) {
        function run(callable $fn, mixed ...$args): bool
        {
            return \Swoole\Coroutine\run($fn, ...$args);
        }

        function go(callable $fn, mixed ...$args): int|false
        {
            return Coroutine::create($fn, ...$args);
        }

        function defer(callable $fn): void
        {
            Coroutine::defer($fn);
        }
    }
}
