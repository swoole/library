<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine;

use Swoole\Constant;
use Swoole\Coroutine;
use Swoole\Coroutine\Server\Connection;
use Swoole\Exception;

class Server
{
    /**
     * Accept errors that concern one incoming connection rather than the listening socket: a connection the peer
     * aborted during the handshake, and the network errors pending on a connection, which Linux reports through
     * accept(2). The server keeps accepting after them. They are given by name, as not every platform has all
     * of them.
     */
    private const TRANSIENT_ACCEPT_ERRORS = [
        'SOCKET_ECONNABORTED',
        'SOCKET_ENETDOWN',
        'SOCKET_EPROTO',
        'SOCKET_ENOPROTOOPT',
        'SOCKET_EHOSTDOWN',
        'SOCKET_ENONET',
        'SOCKET_EHOSTUNREACH',
        'SOCKET_EOPNOTSUPP',
        'SOCKET_ENETUNREACH',
    ];

    /**
     * The number of accept errors in a row the server skips at once. From then on it waits a moment before it
     * accepts again, so that an error that does not go away cannot keep the other coroutines from running, and it
     * raises a warning, once for each run of errors, so that the error does not go unnoticed.
     */
    private const TRANSIENT_ACCEPT_ERRORS_IN_A_ROW = 16;

    /** @var string */
    public $host = '';

    /** @var int */
    public $port = 0;

    /** @var int */
    public $type = AF_INET;

    /** @var int */
    public $fd = -1;

    /** @var int */
    public $errCode = 0;

    /** @var array */
    public $setting = [];

    /** @var bool */
    protected $running = false;

    /** @var callable|null */
    protected $fn;

    /** @var Socket */
    protected $socket;

    /**
     * Server constructor.
     * @throws Exception
     */
    public function __construct(string $host, int $port = 0, bool $ssl = false, bool $reuse_port = false)
    {
        $_host = swoole_string($host);
        if ($_host->contains('::')) {
            $this->type = AF_INET6;
        } elseif ($_host->startsWith('unix:/')) {
            $host       = $_host->substr(5)->__toString();
            $this->type = AF_UNIX;
        } else {
            $this->type = AF_INET;
        }
        $this->host = $host;

        $socket = new Socket($this->type, SOCK_STREAM, 0);
        if ($reuse_port && defined('SO_REUSEPORT')) {
            $socket->setOption(SOL_SOCKET, SO_REUSEPORT, true);
        }
        if (!$socket->bind($this->host, $port)) {
            throw new Exception("bind({$this->host}:{$port}) failed", $socket->errCode);
        }
        if (!$socket->listen()) {
            throw new Exception('listen() failed', $socket->errCode);
        }
        $this->port                = $socket->getsockname()['port'] ?? 0;
        $this->fd                  = $socket->fd;
        $this->socket              = $socket;
        $this->setting['open_ssl'] = $ssl;
    }

    public function set(array $setting): void
    {
        $this->setting = array_merge($this->setting, $setting);
    }

    public function handle(callable $fn): void
    {
        $this->fn = $fn;
    }

    public function shutdown(): bool
    {
        $this->running = false;
        return $this->socket->cancel();
    }

    public function start(): bool
    {
        $this->running = true;
        if ($this->fn === null) {
            $this->errCode = SOCKET_EINVAL;
            return false;
        }
        $socket = $this->socket;
        if (!$socket->setProtocol($this->setting)) {
            $this->errCode = SOCKET_EINVAL;
            return false;
        }

        $skipped = 0;
        while ($this->running) {
            $conn = $socket->accept();
            if ($conn) {
                $skipped = 0;
                $conn->setProtocol($this->setting);
                if (!empty($this->setting[Constant::OPTION_OPEN_SSL])) {
                    $fn = static function ($fn, $connection) {
                        /* @var $connection Connection */
                        if (!$connection->exportSocket()->sslHandshake()) {
                            return;
                        }
                        $fn($connection);
                    };
                    $arguments = [$this->fn, new Connection($conn)];
                } else {
                    $fn        = $this->fn;
                    $arguments = [new Connection($conn)];
                }
                if (Coroutine::create($fn, ...$arguments) === false) {
                    goto _wait;
                }
            } else {
                if ($socket->errCode == SOCKET_EMFILE || $socket->errCode == SOCKET_ENFILE) {
                    _wait:
                    Coroutine::sleep(1);
                    continue;
                }
                if ($socket->errCode == SOCKET_ETIMEDOUT) {
                    continue;
                }
                if (self::isTransientAcceptError($socket->errCode)) {
                    if (++$skipped > self::TRANSIENT_ACCEPT_ERRORS_IN_A_ROW) {
                        if ($skipped === self::TRANSIENT_ACCEPT_ERRORS_IN_A_ROW + 1) {
                            try {
                                trigger_error("accept keeps failing, Error: {$socket->errMsg}[{$socket->errCode}]; the server goes on accepting, a millisecond apart", E_USER_WARNING);
                            } catch (\Throwable) {
                                // An error handler that turns warnings into exceptions must not stop the server: the
                                // warning only reports that it goes on.
                            }
                        }
                        Coroutine::sleep(0.001);
                    }
                    continue;
                }
                if ($socket->errCode == SOCKET_ECANCELED) {
                    break;
                }
                $this->errCode = $socket->errCode;
                trigger_error("accept failed, Error: {$socket->errMsg}[{$socket->errCode}]", E_USER_WARNING);
                return false;
            }
        }

        return true;
    }

    private static function isTransientAcceptError(int $errCode): bool
    {
        static $errors = null;

        if ($errors === null) {
            $errors = [];
            foreach (self::TRANSIENT_ACCEPT_ERRORS as $name) {
                if (defined($name)) {
                    $errors[constant($name)] = true;
                }
            }
        }

        return isset($errors[$errCode]);
    }
}
