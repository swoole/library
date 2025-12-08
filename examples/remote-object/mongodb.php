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
require dirname(__DIR__, 2) . '/src/ext/mongodb.php';

Co\run(function () {
    $client = new Swoole\MongoDB\Client('mongodb://127.0.0.1:27017');
    $list   = $client->listDatabases();
    echo "Available databases:\n";
    foreach ($list as $database) {
        echo "- Name: {$database->getName()}\n";
        echo "  Size: {$database->getSizeOnDisk()} bytes\n";
        echo '  Empty: ' . ($database->isEmpty() ? 'Yes' : 'No') . "\n";
        echo "---\n";
    }
});
