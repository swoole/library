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
use Swoole\Coroutine\Http\Server;
use Swoole\Proxy\HttpProxy;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// HTTP reverse proxy. GET, HEAD, POST, PUT, PATCH, DELETE and OPTIONS are forwarded.
// Do not publish the listen port.
Coroutine\run(static function (): void {
    $server = new Server('127.0.0.1', 9510);
    (new HttpProxy('127.0.0.1', 80))->attach($server);
    $server->start();
});
