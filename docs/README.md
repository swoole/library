# 文档

这里是 Swoole Library 的使用说明。表里的类通过 Composer 的 `Swoole\` 自动加载。gRPC、Debugger、Proxy、Kafka 和 FastCGI 不编译进 Swoole 扩展，安装 `swoole/library` 后即可使用（`swoole.enable_library` 开或关都可以，见根目录 [README.md](../README.md) 的 Autoloading 章节）。在本仓库里跑示例时加上 `-d swoole.enable_library=Off`，这样加载的是仓库里的源码。

## 并入的模块

这些模块原先是独立的包或脚本，现在放在 `src/core/` 下。

| 模块 | 命名空间 | 中文 | English |
| --- | --- | --- | --- |
| gRPC 客户端 | `Swoole\Grpc` | [grpc.md](grpc.md) | [grpc.en.md](grpc.en.md) |
| 远程调试壳 | `Swoole\Debugger` | [debugger.md](debugger.md) | [debugger.en.md](debugger.en.md) |
| 反向代理 | `Swoole\Proxy` | [proxy.md](proxy.md) | [proxy.en.md](proxy.en.md) |
| Kafka 消费者 | `Swoole\Kafka\Consumer` | [kafka/consumer.md](kafka/consumer.md) | [kafka/consumer.en.md](kafka/consumer.en.md) |
| Kafka 生产者 | `Swoole\Kafka\Producer` | [kafka/producer.md](kafka/producer.md) | [kafka/producer.en.md](kafka/producer.en.md) |

Kafka 的 LZ4 记录批需要 `exussum12/xxhash`，`StickyAssignor` 需要 `chdemko/sorted-collections`，Amazon MSK IAM 需要 `aws/aws-sdk-php`。示例在 `examples/kafka`。

## 其他

| 主题 | 文档 |
| --- | --- |
| 用 NameResolver 做服务发现 | [name-resolver.md](name-resolver.md) |
| FastCGI 客户端 | 根目录 [README.md](../README.md#examples-of-fastcgi-calls) 与 `examples/fastcgi` |

## English

The pages above are the user guides. gRPC, Debugger, Proxy, Kafka and FastCGI are loaded by Composer's `Swoole\` autoload and are not compiled into the Swoole extension. Install `swoole/library` to use them, with either `swoole.enable_library` setting (see the Autoloading section of the root [README.md](../README.md)). Examples in this repository need `php -d swoole.enable_library=Off`.
