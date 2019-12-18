<?php
require __DIR__ . '/../bootstrap.php';

use Swoole\Coroutine;
use Swoole\Coroutine\Batch;

Coroutine::create(function () {
    $batch = new Batch([
        function () {
            Coroutine::sleep(1);
            return 'a';
        },
        'k1' =>  function () {
            Coroutine::sleep(1);
            return 'b';
        },
        'k2' =>  function () {
            Coroutine::sleep(1);
            return 'c';
        },
    ]);
    $time = microtime(true);
    $results = $batch->exec();
    $useTime = microtime(true) - $time;
    echo 'results:', PHP_EOL;
    var_dump($results);
    echo 'Use time: ', $useTime, 's', PHP_EOL;
});
