<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

class Greeter
{
    private string $greeting;

    public function __construct(string $greeting = 'Hello')
    {
        $this->greeting = $greeting;
    }

    public function __invoke(string $name): string
    {
        return "{$this->greeting}, {$name}!";
    }
}

$server = new Swoole\RemoteObject\Server(options: [
    'worker_num' => 4,
    'server_mode' => SWOOLE_BASE,
    'enable_coroutine' => false,
    'bootstrap' => __FILE__,
]);
$server->start();