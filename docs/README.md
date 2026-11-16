# Documentation

User guides for Swoole Library. [中文](README-CN.md)

The classes in the tables below are loaded by Composer's `Swoole\` autoload. gRPC, Debugger, Proxy, Kafka and FastCGI are not compiled into the Swoole extension; install `swoole/library` to use them, with either `swoole.enable_library` setting (see the Autoloading section of the root [README.md](../README.md)). Examples in this repository need `php -d swoole.enable_library=Off` so the sources here are loaded.

## Composer-only components

These components are not compiled into the Swoole extension and are loaded by Composer. gRPC, Debugger, Proxy and Kafka were standalone packages or scripts and now live under `src/core/`; the FastCGI protocol part is derived from `lisachenko/protocol-fcgi`.

| Component | Namespace | Guide |
| --- | --- | --- |
| gRPC client | `Swoole\Grpc` | [gRPC client](en/grpc.md) |
| Remote shell | `Swoole\Debugger` | [Remote shell](en/debugger.md) |
| Reverse proxy | `Swoole\Proxy` | [Reverse proxy](en/proxy.md) |
| Kafka consumer | `Swoole\Kafka\Consumer` | [Consumer](en/kafka/consumer.md) |
| Kafka producer | `Swoole\Kafka\Producer` | [Producer](en/kafka/producer.md) |
| FastCGI client | `Swoole\FastCGI`, `Swoole\Coroutine\FastCGI` | [FastCGI client](en/fastcgi.md) |

Kafka's LZ4 record batches need `exussum12/xxhash`, `StickyAssignor` needs `chdemko/sorted-collections`, and Amazon MSK IAM needs `aws/aws-sdk-php`. The examples are in `examples/kafka`.

## Other

| Topic | Guide |
| --- | --- |
| Service discovery with NameResolver | [NameResolver](en/name-resolver.md) |
