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
            ->withDocumentRoot(__DIR__)
            ->withScriptFilename(__DIR__ . '/var.php')
            ->withScriptName('var.php')
            ->withMethod('POST')
            ->withUri('/var?foo=bar&bar=char')
            ->withHeader('X-Foo', 'bar')
            ->withHeader('X-Bar', 'char')
            ->withBody(['foo' => 'bar', 'bar' => 'char']);
        $response = $client->execute($request);
        echo "Result: \n{$response->getBody()}";
    } catch (Client\Exception $exception) {
        echo "Error: {$exception->getMessage()}\n";
    }
});
