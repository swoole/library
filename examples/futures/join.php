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
    $start = microtime(true);

    $future1 = \Swoole\Future::create(static function () {
        return \Swoole\Coroutine\Http\get('https://httpbin.org/delay/2')->getBody();
    });

    $future2 = \Swoole\Future::create(static function () {
        return \Swoole\Coroutine\Http\get('https://httpbin.org/delay/2')->getBody();
    });

    echo implode(PHP_EOL, \Swoole\Future::join([$future1, $future2]));

    printf("Elapsed %f\n", microtime(true) - $start);
});
