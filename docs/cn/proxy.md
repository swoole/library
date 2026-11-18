# 反向代理

`Swoole\Proxy` 是原来的 proxy-server 脚本。类在 `src/core/Proxy/`，由 Composer 加载，不编译进 Swoole 扩展。监听端口由调用方自己的服务器打开，再交给对应的类。后端地址是固定的，这不是开放的正向代理。请把监听地址绑在本机。

可执行脚本在 `examples/proxy`。在本仓库里这样运行：

```shell
php -d swoole.enable_library=Off examples/proxy/co-tcp-proxy.php
php -d swoole.enable_library=Off examples/proxy/tcp-proxy.php
php -d swoole.enable_library=Off examples/proxy/http-proxy.php
```

连不上后端时，TCP 代理直接关掉客户端，不往字节流里写错误文本。HTTP 代理回答 `502`。

## 协程 TCP

`Swoole\Proxy\TcpProxy` 跑在 `Swoole\Coroutine\Server` 里。每个进来的连接都接到同一个后端。构造参数是后端主机、端口、转发超时（默认 `-1`，不限制）和连接超时（默认 `3` 秒）。转发超时必须是正数或 `-1`，连接超时必须是正数。

```php
use Swoole\Coroutine\Server;
use Swoole\Proxy\TcpProxy;

$server = new Server('127.0.0.1', 9508);
(new TcpProxy('127.0.0.1', 80))->attach($server);
$server->start();
```

## 事件驱动 TCP

`Swoole\Proxy\AsyncTcpProxy` 使用 `Swoole\Async\Client` 和 `Swoole\Server`，要在协程外面启动。后端连接从第一段数据开始建立，空闲的客户端不占后端套接字。握手完成前收到的数据会先留着，默认最多 1MB，连上后再写出去。超过上限就关掉这条连接。

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

`accept()` 不做任何事。构造参数在下游对象和后端地址之后，还有连接超时（默认 `3` 秒）和握手前缓存的字节上限（默认 `1048576`）。

## HTTP

`Swoole\Proxy\HttpProxy` 跑在 `Swoole\Coroutine\Http\Server` 上。它转发 `GET`、`HEAD`、`POST`、`PUT`、`PATCH`、`DELETE` 和 `OPTIONS`，并带上状态码和响应头。其他方法回答 `405`，`Allow` 头列出上面这些方法。请求目标不合法时回答 `400`。后端连不上、抛出异常，或状态码不在 100 到 599 之间时回答 `502`。

后端连接会保留下来复用，由连接池限制同时使用的数量。池中的连接空闲超过 `$maxIdleTime` 秒后，下一次取用时关闭并重新连接。后端已经关闭的空闲连接也会被换掉：遇到这种连接的幂等请求（`GET`、`HEAD`、`PUT`、`DELETE`、`OPTIONS`）会换一条新连接重发一次，其他方法直接回答 `502`，因为无法确定后端是否已经执行过。`HEAD`、`204` 和 `304` 不写响应体。

```php
use Swoole\Coroutine\Http\Server;
use Swoole\Proxy\HttpProxy;

$server = new Server('127.0.0.1', 9510);
(new HttpProxy('127.0.0.1', 80))->attach($server);
$server->start();
```

构造参数：

| 参数 | 默认 | 说明 |
| --- | --- | --- |
| 后端主机、端口 |  | 固定的后端。主机不能为空，不能含空白或控制字符，最长 255；端口是 1 到 65535 |
| `$backendSsl` | `false` | 访问后端时是否使用 SSL |
| `$timeout` | `60` | 超时，单位秒，必须是正数 |
| `$preserveHost` | `false` | 为 true 时保留客户端原来的 `Host`。默认改成后端的主机和端口 |
| `$maxConnections` | `256` | 连接池上限：一个代理实例同时使用的后端连接数。`-1` 表示不池化，每个请求用一条新连接（传 `0` 效果相同）。多 worker 时每个 worker 各有一份 |
| `$poolWaitTimeout` | `5` | 池满时请求等待空闲连接的秒数，必须是正数。等不到时请求回答 `502` |
| `$maxIdleTime` | `30` | 连接在池中空闲超过该秒数后不再复用。`0` 表示不按空闲时间淘汰 |
