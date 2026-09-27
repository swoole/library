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

use Swoole\RemoteObject;
use Swoole\RemoteObject\ProxyTrait;

class ProxyGreeter
{
    use ProxyTrait;

    protected RemoteObject $object;

    public function __construct(string $greeting = 'Hello')
    {
        // The Greeter class lives in the remote object server: see bootstrap.php in this directory.
        $client       = swoole_get_default_remote_object_client();
        $this->object = $client->create(Greeter::class, $greeting);
    }

    protected function getObject(): RemoteObject
    {
        return $this->object;
    }
}

Co\run(function () {
    $o = new ProxyGreeter('hello swoole');
    echo $o('rango'), PHP_EOL;

    $client = new RemoteObject\Client(SwooleLibrary::$remote_object_server_socket_file);
    var_dump($client->call('gd_info'));

    $client = swoole_get_default_remote_object_client();
    var_dump($client->call('gd_info'));

    $rs = swoole_dns_get_record('www.baidu.com', DNS_A);
    var_dump($rs);
});
