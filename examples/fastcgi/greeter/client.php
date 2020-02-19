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
use Swoole\FastCGI\HttpRequest;

require __DIR__ . '/../../bootstrap.php';

Coroutine\run(function () {
    try {
        $client = new Client('php-fpm', 9000);
        $request = (new HttpRequest())
            ->withScriptFilename(__DIR__ . '/greeter.php')
            ->withMethod('POST')
            ->withBody(['who' => 'Swoole']);
        $response = $client->execute($request);
        echo "Result: {$response->getBody()}\n";
    } catch (Client\Exception $exception) {
        echo "Error: {$exception->getMessage()}\n";
    }
});
