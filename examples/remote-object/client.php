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

use Swoole\RemoteObject;
use Swoole\RemoteObject\ProxyTrait;

class ProxyGreeter
{
    use ProxyTrait;

    protected RemoteObject $object;

    public function __construct(string $greeting = 'Hello')
    {
        $client       = new RemoteObject\Client();
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

    $client = new RemoteObject\Client();
    var_dump($client->call('gd_info'));
});
