<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

Co\run(function () {
    $client = new Swoole\MongoDB\Client(MONGODB_SERVER_URL);
    $list   = $client->listDatabases();
    echo "Available databases:\n";
    foreach ($list as $database) {
        echo "- Name: {$database->getName()}\n";
        echo "  Size: {$database->getSizeOnDisk()} bytes\n";
        echo '  Empty: ' . ($database->isEmpty() ? 'Yes' : 'No') . "\n";
        echo "---\n";
    }
});
