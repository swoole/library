<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$server = new Swoole\RemoteObject\Server(options: [
    'worker_num' => 4,
    'enable_coroutine' => false,
    'bootstrap' => __FILE__,
]);
$server->start();