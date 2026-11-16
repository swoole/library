# Reverse proxy

`Swoole\Proxy` is the reverse proxy previously published as the proxy-server scripts. The classes live in `src/core/Proxy/`, load through Composer, and are not compiled into the Swoole extension. The caller opens the listening server and hands it to the class. The backend address is fixed, so this is not an open forward proxy. Bind the listening address locally.

The scripts under `examples/proxy` are the executables. From this repository:

```shell
php -d swoole.enable_library=Off examples/proxy/co-tcp-proxy.php
php -d swoole.enable_library=Off examples/proxy/tcp-proxy.php
php -d swoole.enable_library=Off examples/proxy/http-proxy.php
```

A TCP proxy that cannot reach its backend closes the client and writes nothing into the byte stream. The HTTP proxy answers `502`.

## Coroutine TCP

`Swoole\Proxy\TcpProxy` runs on a `Swoole\Coroutine\Server`. Every accepted connection is bridged to the same backend. The constructor takes the backend host, port, relay timeout (default `-1`, no limit) and connect timeout (default `3` seconds). The relay timeout must be positive or `-1`. The connect timeout must be positive.

```php
use Swoole\Coroutine\Server;
use Swoole\Proxy\TcpProxy;

$server = new Server('127.0.0.1', 9508);
(new TcpProxy('127.0.0.1', 80))->attach($server);
$server->start();
```

## Event-driven TCP

`Swoole\Proxy\AsyncTcpProxy` uses `Swoole\Async\Client` and a `Swoole\Server`. Start it from outside a coroutine. The backend connection begins with the first bytes, so an idle client does not hold a backend socket. Bytes that arrive before the handshake finishes are kept, up to 1MB by default, and written once the backend is connected. A larger buffer closes that connection.

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

`accept()` does nothing. After the downstream and the backend address, the constructor takes a connect timeout (default `3` seconds) and the pending-byte limit (default `1048576`).

## HTTP

`Swoole\Proxy\HttpProxy` runs on a `Swoole\Coroutine\Http\Server`. It forwards `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE` and `OPTIONS`, including the status line and the response headers. Anything else is `405`, and the `Allow` header lists those methods. A request target it cannot use is `400`. A backend that cannot be reached, a thrown error, or a status outside 100–599 is `502`.

Each request opens its own connection and closes it afterwards. `HEAD`, `204` and `304` send no body.

```php
use Swoole\Coroutine\Http\Server;
use Swoole\Proxy\HttpProxy;

$server = new Server('127.0.0.1', 9510);
(new HttpProxy('127.0.0.1', 80))->attach($server);
$server->start();
```

Constructor arguments:

| Argument | Default | Meaning |
| --- | --- | --- |
| Backend host and port |  | The fixed backend. The host must be non-empty, at most 255 characters, and free of whitespace and control characters. The port is 1–65535 |
| `$backendSsl` | `false` | Use SSL toward the backend |
| `$timeout` | `60` | Timeout in seconds. Must be positive |
| `$preserveHost` | `false` | Keep the client's `Host` when true. Otherwise the header is the backend host and port |
