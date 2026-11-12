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
use Swoole\Debugger\RemoteShell;
use Swoole\Server;
use Swoole\Timer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$server = new Server('127.0.0.1', 9501);
$server->set([
    'worker_num' => 2,
]);
$server->on('connect', function (Server $server, int $fd): void {
    echo "Client#{$fd}: Connect.\n";
});
$server->on('receive', function (Server $server, int $fd, int $reactorId, string $data): void {
    $server->send($fd, 'Swoole: ' . $data);
});
$server->on('close', function (Server $server, int $fd): void {
    echo "Client#{$fd}: Close.\n";
});
$server->on('pipeMessage', function (Server $server, int $srcWorkerId, mixed $message): void {
    var_dump($srcWorkerId, $message);
});
$server->on('workerStart', function (Server $server, int $workerId): void {
    $GLOBALS['debugger_worker'] = $workerId;
    if ($workerId !== 0) {
        return;
    }
    Coroutine::create(static function (): void {
        Timer::tick(3000, static function (int $timerId): void {
            echo "timer_id #{$timerId}, after 3000ms.\n";
        });
        while (true) {
            Coroutine::sleep(2.0);
        }
    });
});

// The shell evaluates code in the worker. Do not publish this port.
RemoteShell::listen($server);

$server->start();
