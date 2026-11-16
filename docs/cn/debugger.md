# 远程调试壳

`Swoole\Debugger\RemoteShell` 是原来 `swoole/debugger` 里的远程 shell。类在 `src/core/Debugger/`，由 Composer 加载，不编译进 Swoole 扩展。它在已经启动的 `Swoole\Server` 上再听一个端口，用 telnet 连上去。请绑在本机地址上。

```php
\Swoole\Debugger\RemoteShell::listen($server, '127.0.0.1', 9599);
```

`$host` 默认 `127.0.0.1`，`$port` 默认 `9599`。同一进程里再调用一次会抛出 `RuntimeException`。监听失败同样抛出 `RuntimeException`。

`examples/debugger/server.php` 在 `127.0.0.1:9501` 提供应用端口，在 `127.0.0.1:9599` 打开 shell。在本仓库里这样启动：

```shell
php -d swoole.enable_library=Off examples/debugger/server.php
telnet 127.0.0.1 9599
```

一行命令以 `\r\n` 结束。

## 命令

| 命令 | 作用 |
| --- | --- |
| `p`、`print [variable]` | 打印变量。先看当前 worker 里 `exec` 留下的变量，没有再看同名全局变量 |
| `e`、`exec [code]` | 在选中的 worker 里执行 PHP |
| `r`、`reset` | 丢掉当前 worker 里 `exec` 留下的变量。全局变量不动 |
| `w`、`worker [id]` | 切换 worker。编号要落在 `0 .. worker_num + task_worker_num - 1` |
| `l`、`list` | 列出连接 fd |
| `s`、`stats` | 服务器状态 |
| `c`、`coros` | 协程列表 |
| `cs`、`costats` | 协程状态 |
| `el`、`elapsed [cid]` | 某个协程已经运行的时间 |
| `tl`、`timer_list` | 当前进程的定时器 ID |
| `ti`、`timer_info [timer_id]` | 某个定时器的信息 |
| `ts`、`timer_stats` | 当前进程的定时器状态 |
| `b`、`bt [cid]` | 某个协程的调用栈 |
| `i`、`info [fd]` | 某个连接的信息 |
| `h`、`help` | 帮助 |
| `q`、`quit` | 断开 |

`exec` 先把这段代码当作表达式，返回值会被打印，包括 `null`。表达式解析失败时，再当作语句执行，并打印它写出的内容。赋值留在这个 worker 里，下一次 `print` 还能读到，直到 `reset`。以 `__debugger_` 开头的名字由求值器占用。

`listen()` 会包住服务器上已经注册的 `PipeMessage`。发给 worker 的调试命令由 shell 处理，其他消息仍交给原来的回调。请在注册自己的 `PipeMessage` 之后再调用 `listen()`。
