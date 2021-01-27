<?php
use function Swoole\Coroutine\run;
use function Swoole\Coroutine\go;

run(function () {
    sleep(1);
    go(function () {
        usleep(100000);
        echo "co2\n";
    });
    echo "co1\n";
});
