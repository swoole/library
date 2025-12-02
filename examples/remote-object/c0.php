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
    $ro_client = new Swoole\RemoteObject\Client();
    echo $ro_client->call('php_uname', 'm'), PHP_EOL;
    var_dump($ro_client->call('gd_info'));
});
