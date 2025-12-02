<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Co\run(function () {
    $ro_client = new Swoole\RemoteObject\Client();
    $o = $ro_client->create(Greeter::class, "hello swoole");
    echo $o('rango'), PHP_EOL;
});

