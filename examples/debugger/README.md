# Remote shell

`Swoole\Debugger\RemoteShell` is the remote shell previously published as the `swoole/debugger` package. It lives in `src/core/Debugger/`, is loaded through Composer, and is not compiled into the Swoole extension.

Register it on a server. Bind a local address. `exec` evaluates PHP in the selected worker, prints an expression's value, and keeps the variables it assigns there until `reset`. `print` reads those variables, then a global of the same name.

```php
\Swoole\Debugger\RemoteShell::listen($server, '127.0.0.1', 9599);
```

`examples/debugger/server.php` listens on `127.0.0.1:9501` for the application and opens the shell on `127.0.0.1:9599`. Run it with the library sources:

```shell
php -d swoole.enable_library=Off examples/debugger/server.php
telnet 127.0.0.1 9599
```

Commands:

```text
p|print [variable]      print a shell variable, or a global
e|exec [code]           evaluate PHP in the selected worker
r|reset                drop variables assigned by exec
w|worker [id]           switch worker
l|list                  list connection ids
s|stats                 server stats
c|coros                 coroutine list
cs|costats              coroutine stats
el|elapsed [cid]        coroutine elapsed time
tl|timer_list           timer ids
ti|timer_info [timer_id]
ts|timer_stats
b|bt [cid]              coroutine backtrace
i|info [fd]             connection info
h|help
q|quit
```
