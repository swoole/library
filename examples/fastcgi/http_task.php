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
use Swoole\Coroutine\FastCGI;
use Swoole\FastCGI\PHPFPM;

require __DIR__ . '/../bootstrap.php';

Coroutine\run(function () {
    try {
        $result = FastCGI\Client::httpTask(PHPFPM::getDefaultAddress(), __DIR__ . '/hello_world.php');
        echo "Result: {$result}\n";
    } catch (FastCGI\Client\Exception $exception) {
        echo "Error: {$exception->getMessage()}\n";
    }
});
