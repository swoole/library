<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

\Swoole\Coroutine\run(static function () {
    \Swoole\Future::create(static function () {
        throw new RuntimeException('Futures exceptions are propagated');
    })->await();
});
