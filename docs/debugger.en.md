# Remote shell

`Swoole\Debugger\RemoteShell` is the remote shell previously published as `swoole/debugger`. The class lives in `src/core/Debugger/`, loads through Composer, and is not compiled into the Swoole extension. It listens on an extra port of a running `Swoole\Server`. Connect with telnet. Bind that port to a local address.

```php
\Swoole\Debugger\RemoteShell::listen($server, '127.0.0.1', 9599);
```

`$host` defaults to `127.0.0.1` and `$port` to `9599`. A second `listen()` in the same process throws `RuntimeException`. A failed listen throws `RuntimeException` as well.

`examples/debugger/server.php` serves the application on `127.0.0.1:9501` and opens the shell on `127.0.0.1:9599`. From this repository:

```shell
php -d swoole.enable_library=Off examples/debugger/server.php
telnet 127.0.0.1 9599
```

A command ends with `\r\n`.

## Commands

| Command | What it does |
| --- | --- |
| `p`, `print [variable]` | Print a variable. A name set by `exec` in the selected worker wins; otherwise a global of the same name |
| `e`, `exec [code]` | Evaluate PHP in the selected worker |
| `r`, `reset` | Drop the variables `exec` stored in the selected worker. Globals stay |
| `w`, `worker [id]` | Switch worker. The id must be in `0 .. worker_num + task_worker_num - 1` |
| `l`, `list` | List connection ids |
| `s`, `stats` | Server stats |
| `c`, `coros` | Coroutine list |
| `cs`, `costats` | Coroutine stats |
| `el`, `elapsed [cid]` | How long a coroutine has been running |
| `tl`, `timer_list` | Timer ids in this process |
| `ti`, `timer_info [timer_id]` | One timer |
| `ts`, `timer_stats` | Timer stats for this process |
| `b`, `bt [cid]` | Coroutine backtrace |
| `i`, `info [fd]` | One connection |
| `h`, `help` | Help |
| `q`, `quit` | Disconnect |

`exec` first treats the code as an expression and prints the value, including `null`. If that does not parse, it runs the code as a statement and prints what it wrote. An assignment stays in that worker for a later `print`, until `reset`. Names that start with `__debugger_` belong to the evaluator.

`listen()` wraps a `PipeMessage` handler already registered on the server. Shell commands are handled here. Any other pipe message is passed to that handler. Register the application's `PipeMessage` handler before calling `listen()`.
