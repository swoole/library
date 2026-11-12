# 代码审查修复计划

审查范围是 `master` 上未发布的 6.3 库，重点是 gRPC 客户端、数据库代理、FastCGI 和 `swoole_exec()`。下面按严重程度排列。勾选表示代码和对应测试已经落地。

## Bug

- [x] **gRPC 响应竞态。** `Client` 的发送循环在 `Http2\Client::send()` 返回之后才登记 `recvChannelMap`。`send()` 让出时，收包循环可能已经拿到响应，并把它当成“调用方已离开”丢掉。之后 `recv()` 在默认超时 `-1` 上一直等。登记必须容忍响应先到：尚未登记的奇数 stream id 先缓冲。重连会换掉 `$this->client`，发送循环仍可能把旧连接的 stream id 写进新连接的表。用连接代际丢掉跨代的发送结果。
- [x] **`StreamingCall::cancel()` 堵死整条连接。** `cancel()` 只清本地 `streamId`，收包循环仍往容量有限的 channel 里 `push()`。没人再 `pop()` 时，下一次 `push()` 堵住这条连接上的全部 RPC。`cancel()` 要摘掉并关闭该流的 channel，后续帧直接丢弃。`push()` 在 `openStream()` 失败时不得再对 stream 0 调用 `write()`。
- [x] **调用级超时没有用来等响应。** `CallOptions` 的 `timeout` / `deadline` 只写成 `grpc-timeout` 头。unary 的 `recv()` 不传这个时间；`StreamingCall::recv()` 和 `getIterator()` 写死 `-1`，客户端的 `setTimeout()` 也到不了。超时之后的状态是 `UNKNOWN` / “No response”，不是 `DEADLINE_EXCEEDED`。本地等待要使用调用级超时，超时的 triple 和 `Status` 都用 `DEADLINE_EXCEEDED`。
- [x] **mysqli 重连后 `autocommit(false)` 丢失。** `set_charset`、`options`、`change_user` 会重放，`autocommit(false)` 不会。事务中掉线时 `reset()` 先清掉标志，重试发生在 autocommit 打开的新连接上。把成功的 `autocommit()` 记下来，重连后重放。连接池交还连接时强制打开 autocommit，这个记录要改成打开，避免下一个借用者在重连后又被关掉。
- [x] **FastCGI 默认静态过滤会送出源码和敏感文件。** `staticFileFiltrate()` 用区分大小写的 `!== 'php'` 决定是否 `sendfile`。`file.PHP`、`config.php.bak`、`.env` 都会被当成静态文件。脚本扩展名不区分大小写，备份和点文件返回 403。无扩展名文件（例如 `LICENSE`）仍按静态文件发送，和 nginx 对照测试一致。
- [x] **`swoole_exec()` 吃掉行尾空白。** `rtrim()` 不带字符集会去掉空格和制表符。只去掉 `explode(PHP_EOL)` 留下的 `\r`。

## 缺口

- [x] **gRPC 消息没有长度上限。** 帧头里的 length 多大就拼多大。默认拒绝超过 4MB 的消息（与 grpc-go 的默认接收上限一致），在凑齐正文之前就失败。
- [x] **`recv()` 不能跨 HTTP/2 DATA 帧重组。** 重组只在 `getIterator()` 里。`recv()` 遇到被拆开的帧会在 `Parser::unpack()` 抛异常。`recv()` 自己攒齐一条 gRPC 消息再返回，迭代器走同一条路径。
- [x] **不调用 `close()` 时客户端泄漏。** 收发协程若捕获 `$this`，丢掉变量也不会析构。协程改用 `WeakReference`，析构时 `close()`。`VirtualClient` 只在自己创建连接时于析构中关闭；显式 `close()` 仍转发给底层客户端（现有测试约定）。
- [x] **非法 `endpoints` 被静默丢掉。** 主地址不合法会抛异常，附加地址不合法却被忽略。附加地址同样抛 `InvalidArgumentException`。
- [x] **重试退避没有上限和抖动，也不看剩余时间。** `RetryInterceptor` 改用 `Internal\Backoff` 计算延迟，并在 `CallOptions` 的超时已经用完时停止。
- [x] **慢消费者堵死整条 gRPC 连接。** 每个流的 channel 容量改为 16。容量满了之后只再等 1ms（Swoole 把超时 `0` 当成永久等待），仍推不进去就放弃这一条流，收包循环继续服务其他流。

## 保持原样

- **`wait_for_ready`。** 连接在构造时建立，这个选项是按次调用保留的，当前没有连接状态机可以挂起单次 RPC。继续解析并忽略，不假装实现。
- **`RedisPool` 不恢复 `SELECT` / `WATCH`。** 池子交出去的是 `\Redis` 本身，看不见之后的 `select()` 和 `watch()`。要恢复就得加代理，`instanceof \Redis` 和返回类型都会变。维持现在的注释约定。
- **FastCGI 的 `timeout` 是单次 I/O 上限，不是整次请求的截止时间。** `NginxComparisonTest` 的 `stream.php` 总时长约 1.4s，代理超时 0.6s，期望仍然是 200。每个分块都在 0.6s 内完成。改成总截止时间会破坏这个约定。

## PDO

- [x] **非异常错误模式下掉线不重连。** `PDOProxy::__call()` 和 `PDOStatementProxy` 只捕获 `PDOException`。`ERRMODE_SILENT` / `ERRMODE_WARNING` 下失败是 `false` 加 `errorInfo()`。丢失连接的 `false` 走和异常一样的重连或报告路径。普通 SQL 错误仍返回 `false`。
