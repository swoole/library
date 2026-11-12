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

use Swoole\Async\Client;

/**
 * Event-driven reverse TCP proxy on Swoole\Async\Client.
 *
 * Loaded through Composer, not compiled into the extension. Bytes received before the backend
 * handshake completes are kept, up to a limit, and written once the backend is connected.
 * Closing one client cannot mark another client's handshake as local: that state lives on the
 * session, not on the socket number, which is -1 until the socket exists and can be reused.
 * A failed backend closes the client. Nothing is written into the byte stream. Bind the
 * listening address locally.
 */
final class AsyncTcpProxy
{
    private const PENDING_LIMIT = 1048576;

    /** @var array<int, AsyncSession> */
    private array $sessions = [];

    public function __construct(
        private readonly TcpDownstream $downstream,
        private readonly string $backendHost,
        private readonly int $backendPort,
        private readonly float $connectTimeout = 3.0,
        private readonly int $pendingLimit = self::PENDING_LIMIT,
    ) {
        if ($backendHost === '' || strlen($backendHost) > 255 || $backendPort < 1 || $backendPort > 65535 || preg_match('/[\x00-\x20]/', $backendHost) === 1) {
            throw new \InvalidArgumentException("Invalid backend address {$backendHost}:{$backendPort}.");
        }
        if ($connectTimeout <= 0) {
            throw new \InvalidArgumentException('Connect timeout must be positive.');
        }
        if ($pendingLimit < 1) {
            throw new \InvalidArgumentException('Pending byte limit must be positive.');
        }
    }

    /**
     * Accept is a no-op. The backend connection starts with the first bytes, so an idle client
     * does not hold a backend socket.
     */
    public function accept(int $fd): void
    {
    }

    public function receive(int $fd, string $data): void
    {
        if ($data === '') {
            return;
        }
        $session = $this->sessions[$fd] ?? null;
        if ($session === null) {
            $session = $this->open($fd);
            if (!$this->enqueue($fd, $session, $data)) {
                return;
            }
            if (!$session->client->connect($this->backendHost, $this->backendPort, $this->connectTimeout)) {
                $session->finished = true;
                $this->fail($fd);
            } else {
                $session->started = true;
            }

            return;
        }
        if ($session->client->isConnected()) {
            $this->write($fd, $session, $data);

            return;
        }
        $this->enqueue($fd, $session, $data);
    }

    public function close(int $fd): void
    {
        $session = $this->sessions[$fd] ?? null;
        if ($session === null || $session->closing) {
            return;
        }
        $session->closing = true;
        unset($this->sessions[$fd]);
        $this->stop($session);
    }

    private function open(int $fd): AsyncSession
    {
        $client              = new Client(SWOOLE_SOCK_TCP);
        $session             = new AsyncSession($client);
        $this->sessions[$fd] = $session;
        $client->on('connect', function () use ($fd, $session): void {
            if (($this->sessions[$fd] ?? null) !== $session) {
                return;
            }
            $this->flush($session);
        });
        $client->on('error', function () use ($fd, $session): void {
            if (($this->sessions[$fd] ?? null) !== $session) {
                return;
            }
            $session->finished = true;
            $this->fail($fd);
        });
        $client->on('receive', function (Client $client, string $data) use ($fd, $session): void {
            if (($this->sessions[$fd] ?? null) !== $session || $data === '') {
                return;
            }
            if (!$this->downstream->send($fd, $data)) {
                $this->fail($fd);
            }
        });
        $client->on('close', function () use ($fd, $session): void {
            if (($this->sessions[$fd] ?? null) !== $session) {
                return;
            }
            $session->finished = true;
            if ($session->closing) {
                unset($this->sessions[$fd]);

                return;
            }
            $session->closing = true;
            unset($this->sessions[$fd]);
            $this->downstream->close($fd);
        });
        $client->on('bufferFull', function () use ($fd, $session): void {
            if (($this->sessions[$fd] ?? null) !== $session || $session->paused) {
                return;
            }
            $session->paused = true;
            $this->downstream->pause($fd);
        });
        $client->on('bufferEmpty', function () use ($fd, $session): void {
            if (($this->sessions[$fd] ?? null) !== $session) {
                return;
            }
            $this->flush($session);
            if ($session->paused && $session->pending === '') {
                $session->paused = false;
                $this->downstream->resume($fd);
            }
        });

        return $session;
    }

    private function write(int $fd, AsyncSession $session, string $data): void
    {
        $sent = $session->client->send($data);
        if (!is_int($sent) || $sent <= 0) {
            $this->enqueue($fd, $session, $data);
            if (!$session->paused && isset($this->sessions[$fd])) {
                $session->paused = true;
                $this->downstream->pause($fd);
            }

            return;
        }
        if ($sent < strlen($data)) {
            $this->enqueue($fd, $session, substr($data, $sent));
        }
    }

    private function flush(AsyncSession $session): void
    {
        if ($session->closing || $session->pending === '' || !$session->client->isConnected()) {
            return;
        }
        $sent = $session->client->send($session->pending);
        if (!is_int($sent) || $sent <= 0) {
            return;
        }
        if ($sent >= strlen($session->pending)) {
            $session->pending = '';

            return;
        }
        $session->pending = substr($session->pending, $sent);
    }

    private function enqueue(int $fd, AsyncSession $session, string $data): bool
    {
        if ($session->closing) {
            return false;
        }
        if (strlen($session->pending) + strlen($data) > $this->pendingLimit) {
            $this->fail($fd);

            return false;
        }
        $session->pending .= $data;

        return true;
    }

    private function fail(int $fd): void
    {
        $session = $this->sessions[$fd] ?? null;
        if ($session === null || $session->closing) {
            return;
        }
        $session->closing = true;
        unset($this->sessions[$fd]);
        $this->stop($session);
        $this->downstream->close($fd);
    }

    private function stop(AsyncSession $session): void
    {
        if ($session->started && !$session->finished) {
            $session->finished = true;
            $session->client->close();
        }
    }
}
