<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80200) { // @phpstan-ignore smaller.alwaysFalse
    throw new RuntimeException('require PHP version 8.2 or later');
}

if (SWOOLE_USE_SHORTNAME) {
    function _string(string $string = ''): Swoole\StringObject
    {
        return new Swoole\StringObject($string);
    }

    function _mbstring(string $string = ''): Swoole\MultibyteStringObject
    {
        return new Swoole\MultibyteStringObject($string);
    }

    function _array(array $array = []): Swoole\ArrayObject
    {
        return new Swoole\ArrayObject($array);
    }
}

class SwooleLibrary
{
    /**
     * @var array<string, mixed>
     */
    public static array $options = [];

    public static bool $remote_object_server_initiated = false;

    public static string $remote_object_server_socket_file = '';
}

/**
 * @param array<string, mixed> $options
 */
function swoole_library_set_options(array $options): void
{
    SwooleLibrary::$options = $options;
}

function swoole_library_get_options(): array
{
    return SwooleLibrary::$options;
}

function swoole_library_set_option(string $key, mixed $value): void
{
    SwooleLibrary::$options[$key] = $value;
}

function swoole_library_get_option(string $key): mixed
{
    return SwooleLibrary::$options[$key] ?? null;
}

function swoole_string(string $string = ''): Swoole\StringObject
{
    return new Swoole\StringObject($string);
}

function swoole_mbstring(string $string = ''): Swoole\MultibyteStringObject
{
    return new Swoole\MultibyteStringObject($string);
}

function swoole_array(array $array = []): Swoole\ArrayObject
{
    return new Swoole\ArrayObject($array);
}

function swoole_array_list(mixed ...$array): Swoole\ArrayObject
{
    return new Swoole\ArrayObject($array);
}

function swoole_array_default_value(array $array, mixed $key, mixed $default_value = null): mixed
{
    // A null key is the empty string for PHP, which deprecates passing it as null as of PHP 8.5.
    $key = $key ?? '';

    return array_key_exists($key, $array) ? $array[$key] : $default_value;
}

function swoole_is_in_container(): bool
{
    $mountinfo = @file_get_contents('/proc/self/mountinfo');
    return $mountinfo !== false && (str_contains($mountinfo, 'kubepods') || str_contains($mountinfo, 'docker'));
}

function swoole_container_cpu_num(): int
{
    $swoole_cpu_num = intval(getenv('SWOOLE_CPU_NUM'));
    if ($swoole_cpu_num > 0) {
        return $swoole_cpu_num;
    }
    if (!swoole_is_in_container()) {
        return swoole_cpu_num();
    }
    // cgroup v2
    $cpu_max = '/sys/fs/cgroup/cpu.max';
    if (file_exists($cpu_max)) {
        // The file holds "$MAX $PERIOD", e.g. "150000 100000", or "max 100000" when no quota is set.
        $fields   = preg_split('/\s+/', trim((string) @file_get_contents($cpu_max)));
        $quota_us = $fields[0] ?? '';
        if ($quota_us === 'max') {
            return swoole_cpu_num();
        }
        $period_us = $fields[1] ?? '';
    } else {
        $quota_us  = @file_get_contents('/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_quota_us');
        $period_us = @file_get_contents('/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_period_us');
    }
    if (!is_numeric($quota_us) || !is_numeric($period_us)
        || !is_finite((float) $quota_us) || !is_finite((float) $period_us)
        || (float) $quota_us <= 0 || (float) $period_us <= 0) {
        return swoole_cpu_num();
    }
    $cpu_num = floatval($quota_us) / floatval($period_us);
    if (!is_finite($cpu_num) || $cpu_num < 1 || $cpu_num >= PHP_INT_MAX) {
        return swoole_cpu_num();
    }
    return intval(floor($cpu_num));
}

function swoole_init_default_remote_object_server(): void
{
    $verbose  = swoole_library_get_option('default_remote_object_server_verbose') ?: false;
    $dir      = swoole_get_default_remote_object_server_dir();
    $pid_file = $dir . '/remote-object-server.pid';

    $print_log = function ($msg) use ($verbose) {
        if ($verbose) {
            echo $msg . PHP_EOL;
        }
    };

    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
        $print_log("create dir[{$dir}]");
    }

    $options = swoole_library_get_option('default_remote_object_server_options');
    if (!$options) {
        $default_worker_num = defined('SWOOLE_THREAD') ? 128 : 8;
        $worker_num         = swoole_library_get_option('default_remote_object_server_worker_num') ?: $default_worker_num;
        $options            = [
            'worker_num'  => $worker_num,
            'server_mode' => defined('SWOOLE_THREAD') ? SWOOLE_THREAD : SWOOLE_BASE,
        ];
    }
    // The daemon only needs what the library forwards to it; see src/remote_object_config.php.
    // An application that uses the default client for its own remote objects can override these
    // two keys (or set them to [] for no restriction) through default_remote_object_server_options.
    $options += SWOOLE_LIBRARY_REMOTE_OBJECT_CONFIG;

    $log_options = $options;
    unset($log_options['api_key']);
    $print_log('remote object server options: ' . var_export($log_options, true));
    $options['api_key'] = swoole_get_default_remote_object_api_key();

    $php_file                    = $dir . '/remote-object-server.php';
    $socket_file                 = $dir . '/remote-object-server.sock';
    $log_file                    = $dir . '/remote-object-server.log';
    $lock_file                   = $dir . '/remote-object-server.lock';

    $startup_timeout = (float) (swoole_library_get_option('default_remote_object_server_startup_timeout') ?? 10.0);
    if (!is_finite($startup_timeout) || $startup_timeout <= 0) {
        throw new RuntimeException('remote object server startup timeout must be positive and finite');
    }
    $deadline       = hrtime(true) / 1e9 + $startup_timeout;
    $check_deadline = static function () use ($deadline, $log_file): void {
        if (hrtime(true) / 1e9 >= $deadline) {
            throw new RuntimeException("timed out waiting for remote object server; see log[{$log_file}]");
        }
    };
    $wait_ready_fn = static function () use ($socket_file, $pid_file, $print_log, $check_deadline): void {
        while (true) {
            clearstatcache(true, $pid_file);
            if (is_file($pid_file)) {
                $pid = (int) file_get_contents($pid_file);
                if ($pid > 0 && !posix_kill($pid, 0)) {
                    throw new RuntimeException("remote object server process[{$pid}] exited before becoming ready");
                }
            }
            if (posix_access($socket_file, POSIX_R_OK)) {
                // bind() creates the socket file before listen(); concurrent starters must wait for a listener.
                $connection = @stream_socket_client('unix://' . $socket_file, $error_code, $error_message, 0.01);
                if ($connection !== false) {
                    fclose($connection);
                    $print_log('remote object server ready');
                    return;
                }
            }
            $check_deadline();
            $print_log('wait for remote object server ready');
            usleep(10000);
        }
    };

    if (is_file($pid_file)) {
        $pid = (int) file_get_contents($pid_file);
        if ($pid > 0 && posix_kill($pid, 0)) {
            $print_log('remote object server already running');
            $wait_ready_fn();
            return;
        }
    }

    $lock_handle = fopen($lock_file, 'c');
    if (!$lock_handle) {
        throw new RuntimeException("failed to open lock file[{$lock_file}]");
    }
    // If the lock was not acquired, it indicates that another process is trying to start the remote object server.
    // In this case, the service should be skipped from starting and proceed to the ready wait detection branch.
    if (!flock($lock_handle, LOCK_EX | LOCK_NB)) {
        $print_log('remote object server is already running');
        fclose($lock_handle);
        $wait_ready_fn();
        return;
    }

    try {
        $options['enable_coroutine'] = false;
        $options['bootstrap']        = $php_file;
        $options['pid_file']         = $pid_file;
        $options['log_file']         = $log_file;
        $options['daemonize']        = true;
        $options['socket_type']      = SWOOLE_SOCK_UNIX_STREAM;

        // The generated file lives inside the project tree, so PHP-CS-Fixer checks it along with everything else
        // and it has to be written in the project's coding style: standard file header, strict types, braces on
        // their own lines, short array syntax, a trailing comma on every multiline array element and `=>` operators
        // aligned within each array block. var_export() is not used for the options because it emits long array
        // syntax and does not align anything; the array is written out below instead, one element per line.
        $export_array = static function (array $data, int $depth = 1) use (&$export_array): string {
            if (!$data) {
                return '[]';
            }
            $indent = str_repeat('    ', $depth);
            $keys   = array_map(static fn ($key) => var_export($key, true), array_keys($data));
            $width  = max(array_map('strlen', $keys));
            $lines  = [];
            foreach (array_values($data) as $i => $value) {
                if (is_array($value)) {
                    $exported = $export_array($value, $depth + 1);
                } else {
                    $exported = $value === null ? 'null' : var_export($value, true);
                }
                $lines[] = $indent . str_pad($keys[$i], $width) . ' => ' . $exported . ',';
            }
            return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth - 1) . ']';
        };

        $exported_options     = $export_array($options);
        $exported_socket_file = var_export($socket_file, true);

        $temporary_file = tempnam($dir, '.remote-object-server-');
        if ($temporary_file === false || !chmod($temporary_file, 0600)) {
            throw new RuntimeException('failed to create private remote object server script');
        }
        try {
            $rv = file_put_contents($temporary_file, <<<PHP
            <?php
            /**
             * This file is part of Swoole.
             *
             * @link     https://www.swoole.com
             * @contact  team@swoole.com
             * @license  https://github.com/swoole/library/blob/master/LICENSE
             */

            declare(strict_types=1);

            if (is_file(__DIR__ . '/vendor/autoload.php')) {
                require __DIR__ . '/vendor/autoload.php';
            }
            if (is_file(__DIR__ . '/bootstrap.php')) {
                require __DIR__ . '/bootstrap.php';
            }

            (new Swoole\\RemoteObject\\Server({$exported_socket_file}, 0, {$exported_options}))->start();

            PHP);
            if (!$rv || !rename($temporary_file, $php_file)) {
                throw new RuntimeException("failed to write php file[{$php_file}]");
            }
        } finally {
            if (is_file($temporary_file)) {
                unlink($temporary_file);
            }
        }
        $print_log("write php file[{$php_file}]");

        $php_bin = PHP_BINARY;
        if (posix_access($socket_file, POSIX_R_OK)) {
            unlink($socket_file);
            $print_log("remove socket file[{$socket_file}]");
        }

        // Not escapeshellarg(), which drops the bytes that are not valid in the current locale, such as those of a
        // non-ASCII character after setlocale(LC_CTYPE, 'C').
        $quote = static function (string $arg): string {
            return "'" . str_replace("'", "'\\''", $arg) . "'";
        };

        $hook_flags = Swoole\Runtime::getHookFlags();
        // Having enabled the MongoDB hook, you need to install the MongoDB PHP library through Composer.
        if (defined('SWOOLE_HOOK_MONGODB') && $hook_flags & SWOOLE_HOOK_MONGODB && !is_dir($dir . '/vendor/mongodb/mongodb')) {
            system('cd ' . $quote($dir) . ' && composer require mongodb/mongodb');
            $print_log('install mongodb library');
        }

        // Use an argument array so the process handle belongs to PHP itself, rather than a shell.
        $proc = proc_open([$php_bin, $php_file], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if ($proc === false) {
            throw new RuntimeException('failed to start remote object server');
        }
        $print_log('start remote object server');

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        try {
            // Poll without blocking the coroutine scheduler, and drain both pipes while the frontend runs.
            while (true) {
                $status = proc_get_status($proc);
                $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                if (!$status['running']) {
                    break;
                }
                $check_deadline();
                usleep(10000);
            }

            // proc_get_status() gives an exit code, not the encoded status returned by pcntl_waitpid().
            if ($status['exitcode'] !== 0) {
                throw new RuntimeException("failed to start remote object server: exit code {$status['exitcode']}, signal {$status['termsig']}, output: " . $output);
            }
        } finally {
            if ($status['running'] ?? true) {
                proc_terminate($proc, SIGKILL);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
        $print_log('remote object server frontend exited, the process turns to the backend for running');

        $wait_ready_fn();
    } finally {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
    }
}

function swoole_get_default_remote_object_server_dir(): string
{
    $dir = swoole_library_get_option('default_remote_object_server_dir');
    if (empty($dir)) {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $dir  = $home . '/.swoole';
    }
    return $dir;
}

/** @internal The daemon and its clients share a private key without exposing it in process arguments. */
function swoole_get_default_remote_object_api_key(): string
{
    $dir = swoole_get_default_remote_object_server_dir();
    clearstatcache(true, $dir);
    if (!is_dir($dir) || fileowner($dir) !== posix_geteuid() || (fileperms($dir) & 0022) !== 0) {
        throw new RuntimeException('remote object server directory must be owned by this user and not writable by group or others');
    }
    $options = swoole_library_get_option('default_remote_object_server_options') ?? [];
    if (isset($options['api_key'])) {
        if (!is_string($options['api_key']) || $options['api_key'] === '') {
            throw new RuntimeException('remote object server api_key must be a nonempty string');
        }
        return $options['api_key'];
    }
    $file = $dir . '/remote-object-server.key';
    if (!file_exists($file) && !is_link($file)) {
        $temporary = tempnam($dir, '.remote-object-key-');
        if ($temporary === false) {
            throw new RuntimeException('failed to create remote object server key');
        }
        try {
            if (!chmod($temporary, 0600) || file_put_contents($temporary, bin2hex(random_bytes(32))) !== 64) {
                throw new RuntimeException('failed to write remote object server key');
            }
            // link() publishes a complete key without overwriting a concurrent starter's key.
            if (!@link($temporary, $file) && !is_file($file)) {
                throw new RuntimeException('failed to publish remote object server key');
            }
        } finally {
            unlink($temporary);
        }
    }
    clearstatcache(true, $file);
    if (is_link($file) || !is_file($file) || fileowner($file) !== posix_geteuid() || (fileperms($file) & 0077) !== 0) {
        throw new RuntimeException('remote object server key must be a private file owned by this user');
    }
    $key = file_get_contents($file);
    if ($key === false || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
        throw new RuntimeException('invalid remote object server key');
    }
    return $key;
}

function swoole_get_default_remote_object_client(): Swoole\RemoteObject\Client
{
    if (!SwooleLibrary::$remote_object_server_initiated) {
        swoole_init_default_remote_object_server();
        SwooleLibrary::$remote_object_server_initiated = true;
    }
    if (!SwooleLibrary::$remote_object_server_socket_file) {
        $dir                                             = swoole_get_default_remote_object_server_dir();
        SwooleLibrary::$remote_object_server_socket_file = 'unix://' . $dir . '/remote-object-server.sock';
    }
    $options = swoole_library_get_option('default_remote_object_server_options') ?? [];
    if (is_file(swoole_get_default_remote_object_server_dir() . '/remote-object-server.key')) {
        $options['api_key'] = swoole_get_default_remote_object_api_key();
    }
    return new Swoole\RemoteObject\Client(SwooleLibrary::$remote_object_server_socket_file, 0, $options);
}
