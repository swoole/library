<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Proxy;

use Swoole\Coroutine\Client;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Swoole\Coroutine\Socket;

/**
 * Reverse TCP proxy. Loaded through Composer, not compiled into the extension.
 *
 * Each accepted connection is bridged to the same backend. A backend that cannot be reached
 * closes the client without writing anything: this is a byte tunnel, and an error string would
 * corrupt the stream. Bind it to a local address. The event-driven counterpart is AsyncTcpProxy,
 * which uses Swoole\Async\Client.
 */
final class TcpProxy
{
    private readonly float $timeout;

    private readonly float $connectTimeout;

    public function __construct(
        private readonly string $backendHost,
        private readonly int $backendPort,
        float $timeout = -1,
        float $connectTimeout = 3.0,
    ) {
        self::assertEndpoint($backendHost, $backendPort);
        if ($timeout === 0.0 || $timeout < -1) {
            throw new \InvalidArgumentException('Timeout must be positive, or -1 for no timeout.');
        }
        if ($connectTimeout <= 0) {
            throw new \InvalidArgumentException('Connect timeout must be positive.');
        }
        $this->timeout        = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    public function attach(Server $server): void
    {
        $server->handle(function (Connection $connection): void {
            $this->handle($connection);
        });
    }

    public function handle(Connection $connection): void
    {
        $backend = new Client(SWOOLE_SOCK_TCP);
        try {
            if (!$backend->connect($this->backendHost, $this->backendPort, $this->connectTimeout)) {
                return;
            }
            $upstream = $backend->exportSocket();
            if (!$upstream instanceof Socket) {
                return;
            }
            Relay::bridge($connection->exportSocket(), $upstream, $this->timeout);
        } finally {
            $backend->close();
            $connection->close();
        }
    }

    private static function assertEndpoint(string $host, int $port): void
    {
        if ($host === '' || strlen($host) > 255 || $port < 1 || $port > 65535 || preg_match('/[\x00-\x20]/', $host) === 1) {
            throw new \InvalidArgumentException("Invalid backend address {$host}:{$port}.");
        }
    }
}
