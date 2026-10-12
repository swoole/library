<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Atomic;
use Swoole\Coroutine;
use Swoole\Http\Server;
use Swoole\NameResolver\Consul;
use Swoole\NameResolver\Nacos;
use Swoole\NameResolver\Redis;

if (!defined('SWOOLE_LIBRARY')) {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
}
// Give the parent test a process group it can terminate, including server workers, on timeout.
if (posix_setsid() < 0) {
    throw new RuntimeException('Unable to create lifecycle test process group');
}
[$script, $backend, $mode, $redisUrl, $consulUrl, $nacosUrl] = $argv;
$mode                                                        = (int) $mode;
// Source-loaded classes also use the extension's Server Helper hooks.
ini_set('swoole.enable_library', '1');
$resolver = match ($backend) {
    'redis'  => new Redis($redisUrl),
    'consul' => new Consul($consulUrl),
    'nacos'  => new Nacos($nacosUrl),
};
$name    = 'swoole-lifecycle-' . bin2hex(random_bytes(8)) . '.service';
$visible = new Atomic(0);
$server  = new Server('127.0.0.1', 0, $mode);
$server->set(['worker_num' => 2, 'reactor_num' => 1, 'log_file' => '/dev/stderr', 'log_level' => SWOOLE_LOG_WARNING, 'max_wait_time' => 2,
    'service_name'         => $name, 'service_resolver' => $resolver, 'service_heartbeat_interval' => 0.05]);
$server->on('request', static fn ($request, $response) => $response->end('OK'));
$server->on('workerStart', static function ($server, $workerId) use ($resolver, $name, $visible): void {
    fwrite(STDERR, "{$name}: worker {$workerId} started\n");
    if ($workerId !== 1) {
        return;
    }
    Coroutine::create(static function () use ($resolver, $name, $visible, $server): void {
        try {
            $deadline = microtime(true) + 3;
            do {
                if ($resolver->getCluster($name) !== null) {
                    fwrite(STDERR, "{$name}: service became discoverable\n");
                    $visible->set(1);
                    break;
                }
                Coroutine::sleep(0.02);
            } while (microtime(true) < $deadline);
        } finally {
            fwrite(STDERR, "{$name}: requesting shutdown\n");
            $server->shutdown();
        }
    });
});
fwrite(STDERR, "{$backend}/{$mode}: starting server\n");
$server->start();
fwrite(STDERR, "{$backend}/{$mode}: server stopped\n");
if ($visible->get() !== 1) {
    throw new RuntimeException('The server never became discoverable');
}
// In BASE this runs in the manager, where starting a coroutine is forbidden.
$deadline = microtime(true) + 3;
do {
    $cluster = $resolver->getCluster($name);
    if ($cluster === null) {
        break;
    }
    usleep(20000);
} while (microtime(true) < $deadline);
if ($cluster !== null) {
    throw new RuntimeException('The service remained registered after shutdown');
}
echo 'DONE';
