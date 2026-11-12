<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Proxy\AsyncTcpProxy;
use Swoole\Proxy\ServerDownstream;
use Swoole\Server;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Event-driven TCP reverse proxy on Swoole\Async\Client. Do not call this from a coroutine,
// and do not publish the listen port.
$server = new Server('127.0.0.1', 9509, SWOOLE_BASE);
$server->set(['worker_num' => 1]);
$proxy = new AsyncTcpProxy(new ServerDownstream($server), '127.0.0.1', 80);
$server->on('Receive', static function (Server $server, int $fd, int $reactorId, string $data) use ($proxy): void {
    $proxy->receive($fd, $data);
});
$server->on('Close', static function (Server $server, int $fd, int $reactorId) use ($proxy): void {
    $proxy->close($fd);
});
$server->start();
