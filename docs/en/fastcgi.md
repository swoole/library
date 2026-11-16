# FastCGI client

`Swoole\FastCGI` is a FastCGI protocol implementation and `Swoole\Coroutine\FastCGI` is its coroutine client, used to send requests to PHP-FPM. The protocol part is derived from [lisachenko/protocol-fcgi](https://github.com/lisachenko/protocol-fcgi). The classes live in `src/core/FastCGI/` and `src/core/Coroutine/FastCGI/`, load through Composer, and are not compiled into the Swoole extension. They need no extra Composer packages, but do need a running PHP-FPM. Use the full namespaces; the `Co\FastCGI\*` short-name aliases have been removed.

To run the examples in this repository, add `-d swoole.enable_library=Off` so the sources here are loaded:

```shell
php -d swoole.enable_library=Off examples/fastcgi/greeter/client.php
php -d swoole.enable_library=Off examples/fastcgi/greeter/call.php
php -d swoole.enable_library=Off examples/fastcgi/var/client.php
```

## Making a request

`Client` connects to PHP-FPM, `HttpRequest` describes one CGI request, and `execute()` returns an `HttpResponse`. Calls must run inside a coroutine.

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

An address is `host:port`, `unix:/path/to.sock`, or an IPv6 host; the third constructor argument is `$ssl` (default `false`). A failed connect or read throws `Swoole\Coroutine\FastCGI\Client\Exception`. The second argument of `execute()` is a timeout in seconds, default `-1` (no limit).

## One-shot call

`Client::call()` requests a script in one line and returns the response body as a string:

```php
$result = Client::call('php-fpm:9000', __DIR__ . '/greeter.php', ['who' => 'Swoole']);
```

Empty `$data` uses `GET`, otherwise `POST`; an array is encoded as `application/x-www-form-urlencoded`. The fourth argument is a timeout in seconds.

## Request parameters

`HttpRequest` sets CGI parameters with chainable `with*()` methods; `withUri()` splits `REQUEST_URI`, `DOCUMENT_URI` and `QUERY_STRING` at once. The common ones:

| Method | Purpose |
| --- | --- |
| `withScriptFilename()` | Absolute path of the script to run; normally required |
| `withDocumentRoot()` / `withScriptName()` | Document root and script name |
| `withMethod()` / `withUri()` / `withQuery()` | Method, URI, query string |
| `withHeader()` / `withHeaders()` | Request headers, converted to `HTTP_*` parameters |
| `withBody()` | Request body; an array is encoded and `CONTENT_LENGTH` is set |
| `withParam()` / `withParams()` | Set any FastCGI parameter directly |
| `withKeepConn()` | Keep the connection open after the request when `true` |

The defaults are in `src/core/FastCGI/HttpRequest.php`, e.g. `REQUEST_METHOD=GET`, `CONTENT_TYPE=text/plain` and `SERVER_SOFTWARE=swoole/<version>`.

## Response

`HttpResponse` is assembled from `STDOUT`, `STDERR` and `END_REQUEST` records:

| Method | Purpose |
| --- | --- |
| `getStatusCode()` / `getReasonPhrase()` | Status code and reason phrase |
| `getHeaders()` / `getHeader()` | Response headers |
| `getBody()` | Response body |
| `getError()` | Content of `STDERR` |
| `getAppStatus()` / `getProtocolStatus()` | Statuses from `END_REQUEST` |

## Keep-alive and connection pools

A connection is closed after each request by default. `withKeepConn(true)` makes one `Client` reuse it, and `isConnected()` reports whether it is held; when the server closes that connection, the client reconnects before the next request.

```php
$request  = (new HttpRequest())->withScriptFilename($script)->withKeepConn(true);
$response = $client->execute($request);
var_dump($client->isConnected());
```

With `Proxy`, `withConnectionPool($size)` keeps up to `$size` connections open (off by default). Only idempotent requests are retried once when the server closes a pooled connection.

## As a PHP-FPM reverse proxy

`Swoole\Coroutine\FastCGI\Proxy` translates requests from `Swoole\Http\Server` into FastCGI requests for PHP-FPM and writes the response back, while Swoole serves static files itself. `examples/fastcgi/proxy/wordpress.php` is a complete WordPress example:

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

`withTimeout()` sets the timeout, `withIndex()` sets the directory index (default `index.php`; an empty string disables it), `withHttps()` adds the `HTTPS` parameter and switches the scheme to `https`, `withParam()` / `withParams()` add CGI parameters, and `withStaticFileFilter()` replaces the static-file check.

PHP scripts (including an uppercase extension such as `file.PHP`) go to PHP-FPM. Hidden files, backup copies and config files get `403`, missing files get `404`, and source is never sent as a static file. `..` segments in the request path are resolved one by one; a path escaping the document root or containing a null byte gets `400`.

## Protocol codec

`Swoole\FastCGI\*` on its own is a pure PHP protocol implementation with no dependency on the Swoole extension, and can be used to read and write FastCGI records independently: `FastCGI` holds the protocol constants, `Record` and its subclasses (`BeginRequest`, `Params`, `Stdin`, `Stdout`, `Stderr`, `EndRequest`, etc.) represent the record types, `FrameParser::hasFrame()` / `parseFrame()` parse a byte stream, and `Request` / `Response` assemble messages.

```php
use Swoole\FastCGI;
use Swoole\FastCGI\FrameParser;
use Swoole\FastCGI\Record\BeginRequest;

$buffer = (string) new BeginRequest(FastCGI::RESPONDER);
$record = FrameParser::parseFrame($buffer); // BeginRequest
```
