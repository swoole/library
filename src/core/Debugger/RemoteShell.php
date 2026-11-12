<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Debugger;

use Swoole\Coroutine;
use Swoole\Server;
use Swoole\Timer;

/**
 * A telnet shell on an extra port of a Swoole server.
 *
 * Loaded through Composer, not compiled into the extension. Bind it to a local address. The exec
 * command evaluates PHP in the selected worker and prints an expression's value. A variable it
 * assigns stays in that worker until reset, and print reads it before falling back to a global of
 * the same name. Names beginning with __debugger_ are reserved by the evaluator.
 */
class RemoteShell
{
    public const PREFIX = 'DEBUG';

    public const PAGE_SIZE = 20;

    /** @var list<string> */
    private const MENU = [
        "p|print [variable]\t打印全局变量，或 exec 在当前 worker 里设置的变量",
        "e|exec [code]\t在当前 worker 里执行一段 PHP 代码",
        "r|reset\t清空当前 worker 里 exec 设置的变量",
        "w|worker [id]\t切换Worker进程",
        "l|list\t打印服务器所有连接的fd",
        "s|stats\t打印服务器状态",
        "c|coros\t打印协程列表",
        "cs|costats\t打印协程状态",
        "el|elapsed [cid]\t打印某个协程运行的时间",
        "tl|timer_list\t打印当前进程中所有定时器ID",
        "ti|timer_info [timer_id]\t打印某个定时器信息",
        "ts|timer_stats\t打印当前进程中的定时器状态",
        "b|bt [cid]\t打印某个协程调用栈",
        "i|info [fd]\t显示某个连接的信息",
        "h|help\t显示帮助界面",
        "q|quit\t退出终端",
    ];

    /** @var array<int, array{worker_id: int}> */
    private static array $contexts = [];

    /** @var array<string, mixed> variables assigned by exec in this worker */
    private static array $bindings = [];

    private static ?Server $server = null;

    /** @var callable|null */
    private static mixed $originalPipeMessage = null;

    /**
     * Listen for shell clients. The server's PipeMessage handler is wrapped so worker commands
     * still reach a handler the application registered before this call.
     */
    public static function listen(Server $server, string $host = '127.0.0.1', int $port = 9599): void
    {
        if (self::$server !== null) {
            throw new \RuntimeException('RemoteShell is already listening.');
        }
        $listener = $server->listen($host, $port, SWOOLE_SOCK_TCP);
        if ($listener === false) {
            throw new \RuntimeException("RemoteShell failed to listen on {$host}:{$port}.");
        }
        $listener->set([
            'open_eof_split' => true,
            'package_eof'    => "\r\n",
        ]);
        $listener->on('Connect', [self::class, 'onConnect']);
        $listener->on('Close', [self::class, 'onClose']);
        $listener->on('Receive', [self::class, 'onReceive']);

        self::$originalPipeMessage = $server->getCallback('PipeMessage');
        $server->on('PipeMessage', [self::class, 'onPipeMessage']);
        self::$server = $server;
    }

    public static function onConnect(Server $server, int $fd, int $reactorId): void
    {
        self::$contexts[$fd] = ['worker_id' => $server->worker_id];
        self::output($fd, implode("\r\n", self::MENU));
    }

    public static function onClose(Server $server, int $fd, int $reactorId): void
    {
        unset(self::$contexts[$fd]);
    }

    public static function onPipeMessage(Server $server, int $srcWorkerId, mixed $message): mixed
    {
        if (!is_string($message) || !str_starts_with($message, self::PREFIX)) {
            $callback = self::$originalPipeMessage;
            if (!is_callable($callback)) {
                return null;
            }

            return $callback($server, $srcWorkerId, $message);
        }

        $request = unserialize(substr($message, strlen(self::PREFIX)), ['allowed_classes' => false]);
        if (
            !is_array($request)
            || !isset($request['fd'], $request['func'], $request['args'])
            || !is_int($request['fd'])
            || !is_callable($request['func'])
            || !is_array($request['args'])
        ) {
            return null;
        }
        self::call($request['fd'], $request['func'], $request['args']);

        return null;
    }

    public static function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        [$command, $argument] = self::splitCommand($data);
        if ($command === '') {
            self::output($fd, $command);

            return;
        }

        switch ($command) {
            case 'w':
            case 'worker':
                if ($argument === null) {
                    self::output($fd, 'missing worker id.');
                    break;
                }
                $settings      = $server->setting ?? [];
                $workerNum     = (int) ($settings['worker_num'] ?? 0);
                $taskWorkerNum = (int) ($settings['task_worker_num'] ?? 0);
                $workerId      = self::resolveWorkerId($argument, $workerNum, $taskWorkerNum);
                if ($workerId === null) {
                    self::output($fd, "worker_id {$argument} does not exist.");
                    break;
                }
                self::$contexts[$fd]['worker_id'] = $workerId;
                self::output($fd, '[switching to worker ' . $workerId . ']');
                break;
            case 'e':
            case 'exec':
                if ($argument === null || $argument === '') {
                    self::output($fd, 'missing code.');
                    break;
                }
                self::exec($fd, [self::class, 'evalCode'], [$argument]);
                break;
            case 'p':
            case 'print':
                if ($argument === null || $argument === '') {
                    self::output($fd, 'missing variable.');
                    break;
                }
                self::exec($fd, [self::class, 'printVariable'], [$argument]);
                break;
            case 'r':
            case 'reset':
                self::exec($fd, [self::class, 'resetScope'], []);
                break;
            case 'h':
            case 'help':
                self::output($fd, implode("\r\n", self::MENU));
                break;
            case 's':
            case 'stats':
                self::output($fd, var_export($server->stats(), true));
                break;
            case 'c':
            case 'coros':
                self::exec($fd, [self::class, 'printCoroutines'], []);
                break;
            case 'cs':
            case 'costats':
                self::exec($fd, [self::class, 'printCoroutineStats'], []);
                break;
            case 'el':
            case 'elapsed':
                $cid = 0;
                if ($argument !== null) {
                    $cid = self::naturalNumber($argument);
                    if ($cid === null) {
                        self::output($fd, 'invalid coroutine id.');
                        break;
                    }
                }
                self::exec($fd, [self::class, 'printCoroutineElapsed'], [$cid]);
                break;
            case 'bt':
            case 'b':
            case 'backtrace':
                $cid = self::naturalNumber($argument);
                if ($cid === null) {
                    self::output($fd, 'missing coroutine id.');
                    break;
                }
                self::exec($fd, [self::class, 'printBackTrace'], [$cid]);
                break;
            case 'tl':
            case 'timer_list':
                self::exec($fd, [self::class, 'printTimerList'], []);
                break;
            case 'ti':
            case 'timer_info':
                $timerId = self::naturalNumber($argument);
                if ($timerId === null) {
                    self::output($fd, 'missing timer id.');
                    break;
                }
                self::exec($fd, [self::class, 'printTimerInfo'], [$timerId]);
                break;
            case 'ts':
            case 'timer_stats':
                self::exec($fd, [self::class, 'printTimerStats'], []);
                break;
            case 'i':
            case 'info':
                $connection = self::naturalNumber($argument);
                if ($connection === null) {
                    self::output($fd, 'missing fd.');
                    break;
                }
                $info = $server->getClientInfo($connection);
                if ($info === false) {
                    self::output($fd, "connection {$connection} not found.");
                    break;
                }
                self::output($fd, var_export($info, true));
                break;
            case 'l':
            case 'list':
                $pages = self::paginate($server->connections);
                if ($pages === []) {
                    self::output($fd, '[]');
                    break;
                }
                foreach ($pages as $page) {
                    $encoded = json_encode($page);
                    self::output($fd, $encoded === false ? '[]' : $encoded);
                }
                break;
            case 'q':
            case 'quit':
                $server->close($fd);
                break;
            default:
                self::output($fd, "unknown command [{$command}]");
                break;
        }
    }

    /**
     * @return array{0: string, 1: string|null} the command and its argument; the argument is null when absent
     */
    public static function splitCommand(string $line): array
    {
        $parts   = explode(' ', ltrim($line), 2);
        $command = trim($parts[0]);
        if (!isset($parts[1])) {
            return [$command, null];
        }

        return [$command, trim($parts[1])];
    }

    /**
     * Group connection ids into pages of $pageSize. The client connection is not part of this list.
     *
     * @param iterable<mixed> $connectionIds
     * @return list<list<int>>
     */
    public static function paginate(iterable $connectionIds, int $pageSize = self::PAGE_SIZE): array
    {
        $pages = [];
        $page  = [];
        foreach ($connectionIds as $connectionId) {
            $page[] = (int) $connectionId;
            if (count($page) >= $pageSize) {
                $pages[] = $page;
                $page    = [];
            }
        }
        if ($page !== []) {
            $pages[] = $page;
        }

        return $pages;
    }

    /**
     * Worker ids are 0 .. worker_num + task_worker_num - 1. Anything else is rejected.
     */
    public static function resolveWorkerId(string $argument, int $workerNum, int $taskWorkerNum): ?int
    {
        $workerId = self::naturalNumber($argument);
        if ($workerId === null) {
            return null;
        }
        $total = $workerNum + $taskWorkerNum;
        if ($workerId >= $total) {
            return null;
        }

        return $workerId;
    }

    /**
     * Run PHP in this worker. An expression's value is returned, including null. A statement that
     * only prints returns that output. Assignments are kept for a later print in the same worker.
     */
    public static function evaluate(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }

        $attempt = self::runEval('return ' . $code . ';');
        if ($attempt['exception'] instanceof \ParseError) {
            $statement = $code;
            if (!str_ends_with($statement, ';') && !str_ends_with($statement, '}')) {
                $statement .= ';';
            }

            return self::present(self::runEval($statement), false);
        }

        return self::present($attempt, true);
    }

    /**
     * Value of a shell variable in this worker, or of a global when exec has not set that name.
     */
    public static function exportVariable(string $name): string
    {
        $name = ltrim($name, '$ ');
        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            return 'invalid variable name.';
        }
        if (array_key_exists($name, self::$bindings)) {
            return self::dump(self::$bindings[$name]);
        }
        if (array_key_exists($name, $GLOBALS)) {
            return self::dump($GLOBALS[$name]);
        }

        return "undefined variable \${$name}.";
    }

    /**
     * Drop variables assigned by exec in this worker. Globals are left as they are.
     */
    public static function clearScope(): void
    {
        self::$bindings = [];
    }

    /**
     * @param array<int, array<string, mixed>> $frames Coroutine::getBackTrace() frames
     */
    public static function formatTrace(array $frames): string
    {
        $lines = [];
        $seen  = [];
        foreach ($frames as $index => $frame) {
            $object    = '';
            $arguments = $frame['args'] ?? [];
            if (isset($frame['class'])) {
                $object = $frame['class'] . ($frame['type'] ?? '');
            }
            if (!is_array($arguments)) {
                $arguments = [];
            }
            $rendered = [];
            foreach ($arguments as $argument) {
                $rendered[] = self::render($argument, 0, $seen);
            }
            $lines[] = '#' . str_pad((string) $index, 3, ' ')
                . $object . ($frame['function'] ?? '') . '(' . implode(', ', $rendered)
                . ') called at [' . ($frame['file'] ?? '') . ':' . ($frame['line'] ?? '') . ']';
        }

        return implode("\n", $lines);
    }

    private static function resetScope(): void
    {
        self::clearScope();
        echo 'scope cleared.';
    }

    /**
     * @param list<mixed> $arguments
     */
    private static function call(int $fd, callable $callback, array $arguments): void
    {
        $level = ob_get_level();
        ob_start();
        try {
            $callback(...$arguments);
            $printed = self::collectOutput($level);
        } catch (\Throwable $exception) {
            self::discardOutput($level);
            throw $exception;
        }
        self::output($fd, $printed);
    }

    /**
     * @param list<mixed> $arguments
     */
    private static function exec(int $fd, callable $callback, array $arguments): void
    {
        try {
            $server   = self::server();
            $workerId = self::$contexts[$fd]['worker_id'] ?? $server->worker_id;
            if ($workerId !== $server->worker_id) {
                $server->sendMessage(self::PREFIX . serialize([
                    'fd'   => $fd,
                    'func' => $callback,
                    'args' => $arguments,
                ]), $workerId);

                return;
            }
            self::call($fd, $callback, $arguments);
        } catch (\Throwable $exception) {
            self::output($fd, $exception->getMessage());
        }
    }

    private static function output(int $fd, string $message): void
    {
        $server   = self::server();
        $workerId = self::$contexts[$fd]['worker_id'] ?? $server->worker_id;
        $server->send($fd, $message . "\r\nworker#{$workerId}$ ");
    }

    private static function server(): Server
    {
        if (self::$server === null) {
            throw new \LogicException('RemoteShell::listen() has not been called.');
        }

        return self::$server;
    }

    private static function naturalNumber(?string $argument): ?int
    {
        if ($argument === null || preg_match('/^\d+$/', $argument) !== 1) {
            return null;
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($argument) > strlen($max) || (strlen($argument) === strlen($max) && strcmp($argument, $max) > 0)) {
            return null;
        }

        return (int) $argument;
    }

    /**
     * @return array{output: string, result: mixed, errors: list<string>, exception: \Throwable|null}
     */
    private static function runEval(string $code): array
    {
        $errors = [];
        set_error_handler(static function (int $severity, string $message) use (&$errors): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            $errors[] = $message;

            return true;
        });
        $level = ob_get_level();
        ob_start();
        try {
            $result = self::evalInScope($code, self::$bindings);

            return [
                'output'    => self::collectOutput($level),
                'result'    => $result,
                'errors'    => $errors,
                'exception' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'output'    => self::collectOutput($level),
                'result'    => null,
                'errors'    => $errors,
                'exception' => $exception,
            ];
        } finally {
            self::discardOutput($level);
            restore_error_handler();
        }
    }

    /**
     * Read buffers this call opened, innermost first, without touching a buffer that was already open.
     */
    private static function collectOutput(int $level): string
    {
        $chunks = [];
        while (ob_get_level() > $level) {
            $chunk = ob_get_clean();
            if (is_string($chunk) && $chunk !== '') {
                $chunks[] = $chunk;
            }
        }

        return implode('', array_reverse($chunks));
    }

    private static function discardOutput(int $level): void
    {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }

    /**
     * @param array<string, mixed> $__debugger_bindings
     */
    private static function evalInScope(string $__debugger_code, array $__debugger_bindings): mixed
    {
        extract($__debugger_bindings, EXTR_SKIP);
        try {
            return eval($__debugger_code);
        } finally {
            $__debugger_locals = get_defined_vars();
            unset(
                $__debugger_locals['__debugger_code'],
                $__debugger_locals['__debugger_bindings'],
                $__debugger_locals['__debugger_locals']
            );
            self::$bindings = $__debugger_locals;
        }
    }

    /**
     * @param array{output: string, result: mixed, errors: list<string>, exception: \Throwable|null} $attempt
     */
    private static function present(array $attempt, bool $showResult): string
    {
        $parts = [];
        if ($attempt['output'] !== '') {
            $parts[] = rtrim($attempt['output']);
        }
        if ($attempt['errors'] !== []) {
            $parts[] = implode("\n", $attempt['errors']);
        }
        $exception = $attempt['exception'];
        if ($exception !== null) {
            $parts[] = $exception::class . ': ' . $exception->getMessage();
        } elseif ($showResult || $attempt['result'] !== null) {
            $parts[] = '=> ' . self::dump($attempt['result']);
        }

        return implode("\n", $parts);
    }

    private static function dump(mixed $value): string
    {
        ob_start();
        var_dump($value);
        $text = ob_get_clean();

        return $text === false ? '' : rtrim($text);
    }

    private static function printCoroutines(): void
    {
        var_export(iterator_to_array(Coroutine::listCoroutines()));
    }

    private static function printCoroutineStats(): void
    {
        var_export(Coroutine::stats());
    }

    private static function printCoroutineElapsed(int $cid): void
    {
        var_export(Coroutine::getElapsed($cid));
    }

    private static function printTimerList(): void
    {
        var_export(iterator_to_array(Timer::list()));
    }

    private static function printTimerInfo(int $timerId): void
    {
        $info = Timer::info($timerId);
        if ($info === null) {
            echo "timer {$timerId} not found.";

            return;
        }
        var_export($info);
    }

    private static function printTimerStats(): void
    {
        var_export(Timer::stats());
    }

    private static function printBackTrace(int $cid): void
    {
        $frames = Coroutine::getBackTrace($cid);
        if (!is_array($frames) || $frames === []) {
            echo "coroutine {$cid} not found.";

            return;
        }
        echo self::formatTrace($frames);
    }

    private static function printVariable(string $name): void
    {
        echo self::exportVariable($name);
    }

    private static function evalCode(string $code): void
    {
        echo self::evaluate($code);
    }

    /**
     * @param array<int, true> $seen
     */
    private static function render(mixed $value, int $depth, array &$seen): string
    {
        if (is_object($value)) {
            $id = spl_object_id($value);
            if ($depth >= 2 || isset($seen[$id])) {
                return $value::class . ' Object';
            }
            $seen[$id] = true;
            $fields    = [];
            foreach ((array) $value as $key => $field) {
                $name = (string) $key;
                if (str_contains($name, "\0")) {
                    $name = substr($name, (int) strrpos($name, "\0") + 1);
                }
                $fields[] = '[' . $name . '] => ' . self::render($field, $depth + 1, $seen);
            }

            return $value::class . ' Object (' . implode(',', $fields) . ')';
        }
        if (is_array($value)) {
            return 'Array(' . count($value) . ')';
        }

        return var_export($value, true);
    }
}
