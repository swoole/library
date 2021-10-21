<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use function Swoole\Coroutine\run;

const SERVICE_NAME = 'test_service';

run(function () {
    $c = new \Swoole\NameService\Nacos('http://127.0.0.1:8849');
    var_dump($c->join(SERVICE_NAME, '127.0.0.1', 9502));
    var_dump($c->join(SERVICE_NAME, '127.0.0.1', 9501));

    go(function () use ($c) {
        while (true) {
            sleep(1);
            var_dump($c->join(SERVICE_NAME, '127.0.0.1', 9501));
        }
    });
    var_dump($c->resolve(SERVICE_NAME));
});
