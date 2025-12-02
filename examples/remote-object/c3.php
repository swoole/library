<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

Co\run(function () {
    $ro_client = new Swoole\RemoteObject\Client();
    $m = $ro_client->create(MongoDB\Client::class, "remote-object://localhost:27017");
    $collection = $m->myDatabase->users;
    $document = $collection->findOne(['name' => '张三']);
    var_dump($document['name']);
});

