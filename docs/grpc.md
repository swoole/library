# gRPC 客户端

`Swoole\Grpc` 是协程 gRPC 客户端。类在 `src/core/Grpc/`，由 Composer 加载，不编译进 Swoole 扩展。一条 HTTP/2 连接上可以同时发出多个请求，调用必须放在协程里。不要启用 gRPC 的 PHP 扩展。生成的桩和 `examples/grpc` 里的示例需要 `google/protobuf`。

地址写成 `host:port`，也可以写成 `unix:/path`。

## 一元调用

生成的客户端继承 `Swoole\Grpc\BaseStub`。原来的方法仍然返回 `[reply, status, response]`，`$status === 0` 表示成功。

```php
use Swoole\Coroutine;

Coroutine\run(function () {
    $client = new GreeterClient('127.0.0.1:50051');
    $request = new HelloRequest();
    $request->setName('Swoole');
    [$reply, $status] = $client->SayHello($request);
    if ($status === 0) {
        echo $reply->getMessage(), "\n";
    }
    $client->close();
});
```

`invokeUnary()` 返回 `Swoole\Grpc\Result`，用来查看状态，而不只是一个整数：

```php
$result = $client->invokeUnary(
    '/helloworld.Greeter/SayHello',
    $request,
    [HelloReply::class, 'decode']
);
if (!$result->isOk()) {
    echo $result->getStatus()->getName(), "\n";
    echo $result->getStatus()->getMessage(), "\n";
}
```

`$options['grpc.throw_on_error'] = true` 时，生成方法遇到非 OK 状态抛出 `Swoole\Grpc\Status\RpcException`。默认不抛。

`Swoole\Grpc\Status\Status::fromResponse($response)` 读取 gRPC 状态和 trailing metadata。扩展提供 `Response::$trailers` 时用它，否则从 `headers` 里取。标准状态码在 `Swoole\Grpc\Status\StatusCode`。

## 单次调用的选项

生成方法的 `$metadata` 和 `$options` 由 `Swoole\Grpc\CallOptions` 解析。不传这些键时，发出去的头和原来一样。

| 键 | 说明 |
| --- | --- |
| `timeout` | 超时，单位秒，写成 `grpc-timeout`。本地等待超时的状态是 `DEADLINE_EXCEEDED`。`-1` 表示一直等。 |
| `deadline` | 大于 `1e9` 时当作绝对 Unix 时间，否则当作秒数。 |
| `metadata` | 与 `$metadata` 参数合并。键转成小写。以 `-bin` 结尾的值做不带填充的 base64。键只能是字母、数字、`-`、`_`、`.`，并且不能以 `grpc-` 开头；不合规则的键被丢掉。多个值用逗号接在一起。 |
| `compression` | `gzip` 或 `identity`。默认 `identity`。 |
| `grpc.throw_on_error` | 为 true 时非 OK 状态抛 `RpcException`。默认 false。 |
| `headers` | 额外的 HTTP/2 头，键转成小写。 |

一条消息超过 4MB（`Swoole\Grpc\Internal\Frame::DEFAULT_MAX_MESSAGE_BYTES`）时抛出 `InvalidArgumentException`。

## 拦截器

`withInterceptor()` 按注册顺序包在调用外面，后注册的在更外层。`setInterceptors()` 替换整条链。

`Swoole\Grpc\Interceptor\RetryInterceptor` 只重试一元调用。会重试的状态码是 `UNAVAILABLE`、`RESOURCE_EXHAUSTED` 和 `DEADLINE_EXCEEDED`。默认最多 3 次，第一次等待 0.1 秒，之后按指数增加，单次等待不超过 5 秒。本次调用的 deadline 到了就停止。流式调用不会自动重放。只有可以安全重试的请求才应该挂上它。

```php
$client->withInterceptor(new \Swoole\Grpc\Interceptor\RetryInterceptor(3));
```

自己的拦截器实现 `Swoole\Grpc\Interceptor\Interceptor`。`intercept()` 收到 `CallContext` 和下一个调用。一元调用的结果是 `Result`，流式调用的结果是对应的 Call 对象。

## 流式调用

`StreamingCall::recv()` 返回一条已经拼好的 gRPC 消息，格式仍是 `[reply, status, response]`。消息被拆在多个 HTTP/2 DATA 帧里时会先拼起来；一个块里有多条消息时，每次 `recv()` 返回一条。

`getIterator()` 逐条产出反序列化后的消息，流结束或取消后停止。`recvResult()` 返回 `Result`。流结束后用 `getStatus()`、`getTrailers()` 和 `isEnded()` 看终态。

`push()` 继续写入。`end()` 半关闭写端，之后还可以 `recv()`。`cancel()` 只在本地丢掉这个流，状态记为 `CANCELLED`。底层没有按流发送 RST_STREAM 的接口，所以对端不会收到取消。

```php
$call = $watchClient->Watch();
$call->push($watchRequest);
foreach ($call as $reply) {
    // 一条完整消息
}
if (!$call->getStatus()->ok()) {
    echo $call->getStatus()->getName(), "\n";
}
```

不传给 `recv()` 的超时使用这次调用的 `timeout`；调用也没设时使用客户端的 `timeout`。显式传入的值照用，包括 `-1`。

## 连接

构造 `Swoole\Grpc\Client` 或生成客户端时可以传这些选项。默认就是连一次，不发保活，也不做故障转移。

| 键 | 默认 | 说明 |
| --- | --- | --- |
| `timeout` | `-1` | 接收超时，单位秒 |
| `ssl` | `false` | 为 true，或者 `ssl_host_name` 非空时打开 TLS |
| `keepalive_time` | `0` | 空闲时发送 HTTP/2 PING 的间隔，单位秒。`0` 表示不发 |
| `reconnect_attempts` | `1` | 断线后重建传输的次数。`1` 就是只连一次 |
| `reconnect_backoff_initial` | `0.1` | 重连退避的起点，单位秒 |
| `reconnect_backoff_max` | `5` | 退避上限，单位秒 |
| `endpoints` | `[]` | 额外的 `host:port`。断线重连时轮换。仍然只有一条连接，不是按请求做负载均衡 |

没有列在这里的键会交给底层 HTTP/2 客户端，例如 `ssl_cafile`、`ssl_cert_file`、`ssl_key_file`、`ssl_verify_peer`、`ssl_host_name`。`endpoints` 里的非法地址在构造时抛出 `InvalidArgumentException`。

多个生成客户端可以共用一条连接。借用方析构时不会关掉它；显式调用 `close()` 会关掉底层连接，所以只让建连的那一方关闭。

```php
$grpc = new \Swoole\Grpc\Client('127.0.0.1:2379');
$kv = new Etcdserverpb\KVClient('127.0.0.1:2379', ['use' => $grpc]);
$auth = new Etcdserverpb\AuthClient('127.0.0.1:2379', ['use' => $grpc]);
$grpc->close();
```

自己建连的 `Client` 在最后一个引用消失时关闭。

## 示例和生成桩

Helloworld 与 Etcd 的示例在 `examples/grpc`。`examples/grpc/Etcd` 是给示例用的 etcd v3 封装，依赖生成桩和 `google/protobuf`，不是这个包对外提供的类。

已有的桩在 `examples/grpc/generated`。要自己生成时，用 `examples/grpc/tools/generator`，参数与 `protoc` 相同，并且可以把一个目录当作参数，生成该目录下的 proto：

```shell
examples/grpc/tools/generator \
  --proto_path=examples/grpc/proto \
  --php_out=examples/grpc/generated/Grpc \
  --grpc_out=examples/grpc/generated/Grpc \
  --plugin=protoc-gen-grpc=/path/to/grpc_php_plugin \
  examples/grpc/proto
```

在本仓库里运行示例：

```shell
php -d swoole.enable_library=Off examples/grpc/grpc/greeter_client.php
```
