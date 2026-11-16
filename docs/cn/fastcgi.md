# FastCGI 客户端

`Swoole\FastCGI` 是 FastCGI 协议实现，`Swoole\Coroutine\FastCGI` 是它的协程客户端，用来把请求发给 PHP-FPM。协议部分衍生自 [lisachenko/protocol-fcgi](https://github.com/lisachenko/protocol-fcgi)。类在 `src/core/FastCGI/` 和 `src/core/Coroutine/FastCGI/`，由 Composer 加载，不编译进 Swoole 扩展；没有额外的 Composer 依赖，但需要一个正在运行的 PHP-FPM。请写完整命名空间，`Co\FastCGI\*` 短名别名已经移除。

在本仓库里跑示例要加上 `-d swoole.enable_library=Off`，这样加载的是仓库里的源码：

```shell
php -d swoole.enable_library=Off examples/fastcgi/greeter/client.php
php -d swoole.enable_library=Off examples/fastcgi/greeter/call.php
php -d swoole.enable_library=Off examples/fastcgi/var/client.php
```

## 发起请求

`Client` 连到 PHP-FPM，`HttpRequest` 描述一次 CGI 请求，`execute()` 返回 `HttpResponse`。调用必须放在协程里。

```php
use Swoole\Coroutine;
use Swoole\Coroutine\FastCGI\Client;
use Swoole\FastCGI\HttpRequest;

Coroutine\run(function () {
    $client  = new Client('php-fpm', 9000);
    $request = (new HttpRequest())
        ->withScriptFilename(__DIR__ . '/greeter.php')
        ->withMethod('POST')
        ->withBody(['who' => 'Swoole']);
    $response = $client->execute($request);

    echo $response->getStatusCode(), "\n";
    echo $response->getBody(), "\n";
});
```

地址可以写成 `host:port`、`unix:/path/to.sock` 或 IPv6 主机；构造函数的第三个参数是 `$ssl`（默认 `false`）。连接或读取失败抛出 `Swoole\Coroutine\FastCGI\Client\Exception`。`execute()` 的第二个参数是超时秒数，默认 `-1` 不限制。

## 快捷调用

`Client::call()` 一行就能请求一个脚本，返回响应体字符串：

```php
$result = Client::call('php-fpm:9000', __DIR__ . '/greeter.php', ['who' => 'Swoole']);
```

`$data` 为空用 `GET`，否则用 `POST`；传数组会按 `application/x-www-form-urlencoded` 编码。第四个参数是超时秒数。

## 请求参数

`HttpRequest` 用 `with*()` 设置 CGI 参数，可以链式调用；`withUri()` 一次拆出 `REQUEST_URI`、`DOCUMENT_URI` 和 `QUERY_STRING`。常用的有：

| 方法 | 作用 |
| --- | --- |
| `withScriptFilename()` | 要执行的脚本绝对路径，通常必填 |
| `withDocumentRoot()` / `withScriptName()` | 文档根目录与脚本名 |
| `withMethod()` / `withUri()` / `withQuery()` | 请求方法、URI、查询串 |
| `withHeader()` / `withHeaders()` | 请求头，转成 `HTTP_*` 参数 |
| `withBody()` | 请求体；数组自动编码并带上 `CONTENT_LENGTH` |
| `withParam()` / `withParams()` | 直接设置任意 FastCGI 参数 |
| `withKeepConn()` | 为 `true` 时请求结束后不断开连接 |

默认值见 `src/core/FastCGI/HttpRequest.php`，例如 `REQUEST_METHOD=GET`、`CONTENT_TYPE=text/plain`、`SERVER_SOFTWARE=swoole/<版本>`。

## 响应

`HttpResponse` 从 `STDOUT`、`STDERR` 和 `END_REQUEST` 记录里组装：

| 方法 | 作用 |
| --- | --- |
| `getStatusCode()` / `getReasonPhrase()` | 状态码与原因短语 |
| `getHeaders()` / `getHeader()` | 响应头 |
| `getBody()` | 响应体 |
| `getError()` | `STDERR` 的内容 |
| `getAppStatus()` / `getProtocolStatus()` | `END_REQUEST` 里的状态 |

## 长连接与连接池

请求默认用完就关连接。`withKeepConn(true)` 让同一个 `Client` 复用连接，`isConnected()` 可以查询；服务端关掉这条连接时，客户端会在下次请求前自动重连。

```php
$request  = (new HttpRequest())->withScriptFilename($script)->withKeepConn(true);
$response = $client->execute($request);
var_dump($client->isConnected());
```

用 `Proxy` 时，`withConnectionPool($size)` 会保持最多 `$size` 条连接（默认关闭）。只有幂等请求会在连接被服务端关闭后自动重试一次。

## 作为 PHP-FPM 反向代理

`Swoole\Coroutine\FastCGI\Proxy` 把 `Swoole\Http\Server` 的请求翻译成 FastCGI 请求交给 PHP-FPM，再把响应写回客户端；静态文件由 Swoole 自己发送。`examples/fastcgi/proxy/wordpress.php` 是一个完整的 WordPress 例子：

```php
use Swoole\Coroutine\FastCGI\Proxy;
use Swoole\Http\Server;

$server = new Server('0.0.0.0', 80, SWOOLE_BASE);
$proxy  = (new Proxy('wordpress:9000', '/var/www/html'))->withConnectionPool(16);
$server->on('request', function ($request, $response) use ($proxy) {
    $proxy->pass($request, $response);
});
$server->start();
```

`withTimeout()` 设置超时，`withIndex()` 设置目录默认脚本（默认 `index.php`，传空字符串关闭），`withHttps()` 会加上 `HTTPS` 参数并把 scheme 改成 `https`，`withParam()` / `withParams()` 追加 CGI 参数，`withStaticFileFilter()` 替换静态文件判断。

PHP 脚本（含 `file.PHP` 这样的大写扩展名）交给 PHP-FPM；隐藏文件、备份文件和配置文件回答 `403`，找不到的文件回答 `404`，不会把源码当静态文件发出去。请求路径里的 `..` 会被逐段消解，越出文档根目录或含空字节时回答 `400`。

## 协议编解码层

`Swoole\FastCGI\*` 本身是纯 PHP 的协议实现，不依赖 Swoole 扩展，可以单独用来读写 FastCGI 记录：`FastCGI` 存放协议常量，`Record` 及其子类（`BeginRequest`、`Params`、`Stdin`、`Stdout`、`Stderr`、`EndRequest` 等）表示各类记录，`FrameParser::hasFrame()` / `parseFrame()` 解析字节流，`Request` / `Response` 组装消息。

```php
use Swoole\FastCGI;
use Swoole\FastCGI\FrameParser;
use Swoole\FastCGI\Record\BeginRequest;

$buffer = (string) new BeginRequest(FastCGI::RESPONDER);
$record = FrameParser::parseFrame($buffer); // BeginRequest
```
