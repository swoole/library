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

class Greeter implements Iterator, Countable
{
    public array $list = [
        'world',
        'swoole',
        'php',
        'go',
        'python',
        'java',
        'c++',
        'c',
        'nodejs',
        'ruby',
        'perl',
        'lua',
        'swift',
        'objective-c',
        'rust',
        'kotlin',
        'scala',
        'haskell',
        'lisp',
        'clojure',
        'elixir',
        'erlang',
    ];

    private string $greeting;

    private int $index = 0;

    public function __construct(string $greeting = 'Hello')
    {
        $this->greeting = $greeting;
    }

    public function __invoke(string $name): string
    {
        return "{$this->greeting}, {$name}!";
    }

    public function current(): mixed
    {
        return $this->list[$this->index];
    }

    public function next(): void
    {
        $this->index++;
    }

    public function key(): mixed
    {
        return $this->index;
    }

    public function valid(): bool
    {
        return $this->index < count($this->list);
    }

    public function rewind(): void
    {
        $this->index = 0;
    }

    public function count(): int
    {
        return count($this->list);
    }
}

//$server = new Swoole\RemoteObject\Server(options: [
//    'worker_num'       => 4,
//    'server_mode'      => SWOOLE_BASE,
//    'enable_coroutine' => false,
//    'bootstrap'        => __FILE__,
//    'pid_file'         => __DIR__ . '/server.pid',
//]);
//$server->start();

require_once dirname(__DIR__, 2) . '/src/ext/standard.php';

(new Swoole\RemoteObject\Server('/home/swoole/.swoole/remote-object-server.sock', 0, [
    'worker_num'       => 8,
    'server_mode'      => 3,
    'enable_coroutine' => false,
    'bootstrap'        => __FILE__,
    'pid_file'         => '/home/swoole/.swoole/remote-object-server.pid',
    'log_file'         => '/home/swoole/.swoole/remote-object-server.log',
    'socket_type'      => 5,
]))->start();
