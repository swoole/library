# 文档

Swoole Library 的使用说明。[English](README.md)

表里的类通过 Composer 的 `Swoole\` 自动加载。gRPC、Debugger、Proxy、Kafka 和 FastCGI 不编译进 Swoole 扩展，安装 `swoole/library` 后即可使用（`swoole.enable_library` 开或关都可以，见根目录 [README.md](../README.md) 的 Autoloading 章节）。在本仓库里跑示例时加上 `-d swoole.enable_library=Off`，这样加载的是仓库里的源码。

## Composer-only 模块

这些模块不编译进 Swoole 扩展，由 Composer 加载。gRPC、Debugger、Proxy 和 Kafka 原先是独立的包或脚本，现在放在 `src/core/` 下；FastCGI 的协议部分衍生自 `lisachenko/protocol-fcgi`。

| 模块 | 命名空间 | 文档 |
| --- | --- | --- |
| gRPC 客户端 | `Swoole\Grpc` | [gRPC 客户端](cn/grpc.md) |
| 远程调试壳 | `Swoole\Debugger` | [远程调试壳](cn/debugger.md) |
| 反向代理 | `Swoole\Proxy` | [反向代理](cn/proxy.md) |
| Kafka 消费者 | `Swoole\Kafka\Consumer` | [消费者](cn/kafka/consumer.md) |
| Kafka 生产者 | `Swoole\Kafka\Producer` | [生产者](cn/kafka/producer.md) |
| FastCGI 客户端 | `Swoole\FastCGI`、`Swoole\Coroutine\FastCGI` | [FastCGI 客户端](cn/fastcgi.md) |

Kafka 的 LZ4 记录批需要 `exussum12/xxhash`，`StickyAssignor` 需要 `chdemko/sorted-collections`，Amazon MSK IAM 需要 `aws/aws-sdk-php`。示例在 `examples/kafka`。

## 其他

| 主题 | 文档 |
| --- | --- |
| 用 NameResolver 做服务发现 | [NameResolver](en/name-resolver.md)（仅英文） |
