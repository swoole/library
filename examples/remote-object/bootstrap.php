<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

/*
 * Bootstrap file of the default remote object server. It runs inside the server daemon only, never in a client.
 *
 * No file refers to it by its path. swoole_init_default_remote_object_server() (src/functions.php) generates the
 * script the daemon runs, remote-object-server.php, in the server directory, and that script loads the file named
 * bootstrap.php next to it, if there is one. tests/bootstrap.php makes this directory the server directory (option
 * "default_remote_object_server_dir"), for the unit tests and for every example that loads examples/bootstrap.php.
 *
 * It does two things for the daemon:
 *   1. It loads the Composer autoloader. The generated script only looks for a vendor directory next to itself,
 *      and there is none here; without this line the daemon has no library when the one embedded in the extension
 *      is turned off, and no mongodb/mongodb package for Swoole\MongoDB\Client.
 *   2. It defines the classes a client asks the server to create: a class has to exist on the server side, the
 *      client only holds a proxy.
 *
 * This is a test fixture as much as an example: tests/unit/RemoteObjectTest.php creates \Greeter on the server,
 * and so does client.php in this directory. Not to be confused with examples/bootstrap.php, which the example
 * scripts load on the client side.
 */

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

    private int $index = 0;

    public function __construct(private readonly string $greeting = 'Hello')
    {
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
