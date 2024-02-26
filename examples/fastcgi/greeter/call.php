<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\FastCGI\Client;

require dirname(__DIR__, 2) . '/bootstrap.php';

Coroutine\run(function () {
    try {
        $result = Client::call(
            'php-fpm:9000',
            __DIR__ . '/greeter.php',
            ['who' => 'Swoole']
        );
        echo "Result: {$result}\n";
    } catch (Client\Exception $exception) {
        echo "Error: {$exception->getMessage()}\n";
    }
});
