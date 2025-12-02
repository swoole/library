<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

Co\run(function () {
    $client     = new Swoole\RemoteObject\Client();
    $mongo      = $client->create(MongoDB\Client::class, 'mongodb://localhost:27017');
    $collection = $mongo->myDatabase->users;
    $document   = $collection->findOne(['name' => '张三']);
    var_dump($document['name']);
});
