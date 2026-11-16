# gRPC client

`Swoole\Grpc` is a coroutine gRPC client. The classes live in `src/core/Grpc/`, load through Composer, and are not compiled into the Swoole extension. One HTTP/2 connection carries many calls at once. Make the calls from a coroutine. Do not enable the gRPC PHP extension. Generated stubs and the examples under `examples/grpc` need `google/protobuf`.

An address is `host:port`, or `unix:/path`.

## Unary calls

A generated client extends `Swoole\Grpc\BaseStub`. Its methods still return `[reply, status, response]`. `$status === 0` means OK.

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

`invokeUnary()` returns a `Swoole\Grpc\Result`:

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

With `$options['grpc.throw_on_error'] = true`, a generated method throws `Swoole\Grpc\Status\RpcException` when the status is not OK. The default is to return the triple.

`Swoole\Grpc\Status\Status::fromResponse($response)` reads the gRPC status and trailing metadata. It uses `Response::$trailers` when the extension provides that property, and otherwise reads `headers`. The standard codes are `Swoole\Grpc\Status\StatusCode`.

## Per-call options

`Swoole\Grpc\CallOptions` reads the `$metadata` and `$options` arguments of a generated method. Leaving the keys below unset sends the same headers as before.

| Key | Meaning |
| --- | --- |
| `timeout` | Seconds, sent as `grpc-timeout`. A local wait that runs out is `DEADLINE_EXCEEDED`. `-1` waits without a limit. |
| `deadline` | A value above `1e9` is an absolute Unix time. Anything smaller is a duration in seconds. |
| `metadata` | Merged with the `$metadata` argument. Keys are lowercased. A value whose key ends in `-bin` is base64-encoded without padding. A key must be letters, digits, `-`, `_` and `.`, and must not start with `grpc-`. Any other key is dropped. Several values are joined with a comma. |
| `compression` | `gzip` or `identity`. The default is `identity`. |
| `grpc.throw_on_error` | Throw `RpcException` when the status is not OK. Default false. |
| `headers` | Extra HTTP/2 headers. Keys are lowercased. |

A message larger than 4MB (`Swoole\Grpc\Internal\Frame::DEFAULT_MAX_MESSAGE_BYTES`) throws `InvalidArgumentException`.

## Interceptors

`withInterceptor()` wraps the call. The last interceptor registered is the outermost. `setInterceptors()` replaces the chain.

`Swoole\Grpc\Interceptor\RetryInterceptor` retries unary calls only. The codes it retries are `UNAVAILABLE`, `RESOURCE_EXHAUSTED` and `DEADLINE_EXCEEDED`. The default is 3 attempts, starting at 0.1 seconds and growing exponentially, with each delay capped at 5 seconds. It stops when the call's deadline has passed. Streaming calls are not replayed. Register it only for a call that can be sent again.

```php
$client->withInterceptor(new \Swoole\Grpc\Interceptor\RetryInterceptor(3));
```

An interceptor implements `Swoole\Grpc\Interceptor\Interceptor`. `intercept()` receives a `CallContext` and the next callable. A unary call yields a `Result`. A streaming call yields its Call object.

## Streaming

`StreamingCall::recv()` returns one complete gRPC message, still as `[reply, status, response]`. A message split across HTTP/2 DATA frames is reassembled first. Several messages in one chunk come back one `recv()` at a time.

`getIterator()` yields each deserialized message and stops when the stream ends or is cancelled. `recvResult()` returns a `Result`. After the stream ends, `getStatus()`, `getTrailers()` and `isEnded()` report the outcome.

`push()` writes another message. `end()` half-closes the write side; `recv()` can still read. `cancel()` drops the stream locally and records `CANCELLED`. The HTTP/2 client has no per-stream RST_STREAM, so the peer is not told.

```php
$call = $watchClient->Watch();
$call->push($watchRequest);
foreach ($call as $reply) {
    // one complete message
}
if (!$call->getStatus()->ok()) {
    echo $call->getStatus()->getName(), "\n";
}
```

A `recv()` without a timeout uses the per-call `timeout`, then the client `timeout`. An explicit argument is used as given, including `-1`.

## Connections

These options may be passed to `Swoole\Grpc\Client` or to a generated client. The defaults connect once, send no keepalive, and do not fail over.

| Key | Default | Meaning |
| --- | --- | --- |
| `timeout` | `-1` | Receive timeout, in seconds |
| `ssl` | `false` | TLS is on when this is true, or when `ssl_host_name` is non-empty |
| `keepalive_time` | `0` | Seconds between HTTP/2 PINGs while idle. `0` sends none |
| `reconnect_attempts` | `1` | How many times to rebuild the transport after a drop. `1` is a single connect |
| `reconnect_backoff_initial` | `0.1` | First reconnect delay, in seconds |
| `reconnect_backoff_max` | `5` | Largest reconnect delay, in seconds |
| `endpoints` | `[]` | Extra `host:port` values. A reconnect rotates through them. There is still one connection, not per-request load balancing |

Any other key is forwarded to the HTTP/2 client, including `ssl_cafile`, `ssl_cert_file`, `ssl_key_file`, `ssl_verify_peer` and `ssl_host_name`. An illegal entry in `endpoints` throws `InvalidArgumentException` from the constructor.

Several generated clients can share one connection. A client that borrowed it does not close it on destruction. An explicit `close()` does close the underlying connection, so close it from the side that opened it.

```php
$grpc = new \Swoole\Grpc\Client('127.0.0.1:2379');
$kv = new Etcdserverpb\KVClient('127.0.0.1:2379', ['use' => $grpc]);
$auth = new Etcdserverpb\AuthClient('127.0.0.1:2379', ['use' => $grpc]);
$grpc->close();
```

A `Client` that opened its own connection closes when the last reference to it is dropped.

## Examples and stubs

Helloworld and Etcd examples are under `examples/grpc`. `examples/grpc/Etcd` is an etcd v3 helper for those examples. It needs the generated stubs and `google/protobuf`, and it is not a class this package publishes.

The stubs already generated are in `examples/grpc/generated`. To generate your own, run `examples/grpc/tools/generator`. Its arguments match `protoc`, and a directory argument generates every proto file under it:

```shell
examples/grpc/tools/generator \
  --proto_path=examples/grpc/proto \
  --php_out=examples/grpc/generated/Grpc \
  --grpc_out=examples/grpc/generated/Grpc \
  --plugin=protoc-gen-grpc=/path/to/grpc_php_plugin \
  examples/grpc/proto
```

Run an example from this repository with:

```shell
php -d swoole.enable_library=Off examples/grpc/grpc/greeter_client.php
```
