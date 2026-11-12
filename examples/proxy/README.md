# Reverse proxy

`Swoole\Proxy` is the reverse proxy previously published as the proxy-server scripts. The classes live in `src/core/Proxy/` and are loaded through Composer. They are not compiled into the Swoole extension. The scripts in this directory are the executables: they create the listening server and call the class. The backend address is fixed. Bind a local address.

`co-tcp-proxy.php` runs the coroutine TCP tunnel inside `Swoole\Coroutine\run()`:

```php
use Swoole\Coroutine\Server;
use Swoole\Proxy\TcpProxy;

$server = new Server('127.0.0.1', 9508);
(new TcpProxy('127.0.0.1', 80))->attach($server);
$server->start();
```

`tcp-proxy.php` is the event-driven TCP proxy. It uses `Swoole\Async\Client` and a `Swoole\Server`, so start it from outside a coroutine:

```php
use Swoole\Proxy\AsyncTcpProxy;
use Swoole\Proxy\ServerDownstream;
use Swoole\Server;

$server = new Server('127.0.0.1', 9509, SWOOLE_BASE);
$proxy = new AsyncTcpProxy(new ServerDownstream($server), '127.0.0.1', 80);
$server->on('Receive', static function (Server $server, int $fd, int $reactorId, string $data) use ($proxy): void {
    $proxy->receive($fd, $data);
});
$server->on('Close', static function (Server $server, int $fd, int $reactorId) use ($proxy): void {
    $proxy->close($fd);
});
$server->start();
```

`http-proxy.php` forwards `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE` and `OPTIONS`, including the status line and the response headers. Anything else is `405`. A backend that cannot be reached is `502`.

```php
use Swoole\Coroutine\Http\Server;
use Swoole\Proxy\HttpProxy;

$server = new Server('127.0.0.1', 9510);
(new HttpProxy('127.0.0.1', 80))->attach($server);
$server->start();
```

Run them with the library sources:

```shell
php -d swoole.enable_library=Off examples/proxy/co-tcp-proxy.php
php -d swoole.enable_library=Off examples/proxy/tcp-proxy.php
php -d swoole.enable_library=Off examples/proxy/http-proxy.php
```

A TCP proxy that cannot reach its backend closes the client. It does not write an error string into the byte stream.
