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
use Swoole\NameResolver\Redis;

if (!defined('SWOOLE_LIBRARY')) {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
}

Coroutine\run(function (): void {
    $resolver = (new Redis(getenv('REGISTRY_URL') ?: 'redis://127.0.0.1:6379'))->withTimeout(1);
    $server   = new Server('0.0.0.0', (int) (getenv('SERVICE_PORT') ?: 9501));
    $server->set([
        'service_name'    => getenv('SERVICE_NAME') ?: 'orders.service',
        'service_address' => getenv('SERVICE_IP') ?: '127.0.0.1',
        'name_resolver'   => [$resolver],
    ]);
    $server->handle('/', static fn ($request, $response) => $response->end('OK'));
    if (PHP_OS_FAMILY !== 'Windows') {
        Swoole\Process::signal(SIGTERM, static fn () => $server->shutdown());
    }
    // Registration, heartbeat and deregistration are managed by start()/shutdown().
    $server->start();
});
