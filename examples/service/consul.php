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
    $c = new \Swoole\NameService\Consul('http://127.0.0.1:8500');
    $c->join('test_service', '127.0.0.1', 9502);
    var_dump($c->resolve(SERVICE_NAME));
});
