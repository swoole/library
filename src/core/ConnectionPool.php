<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole;

use Swoole\Coroutine\Channel;

class ConnectionPool
{
    public const DEFAULT_SIZE = 64;

    protected ?Channel $pool;

    /** @var callable */
    protected $constructor;

    protected int $num = 0;

    public function __construct(callable $constructor, protected int $size = self::DEFAULT_SIZE, protected ?string $proxy = null)
    {
        $this->pool        = new Channel($this->size);
        $this->constructor = $constructor;
    }

    public function fill(): void
    {
        if ($this->pool === null) {
            // A closed pool has nowhere to keep the connections: they would be made, counted and dropped.
            return;
        }
        while ($this->size > $this->num) {
            $this->make();
        }
    }

    /**
     * Get a connection from the pool.
     *
     * @param float $timeout the number of seconds to wait for a connection; 0 or less waits with no time limit
     * @return mixed a connection object from the pool, or false if no connection is available before the timeout is
     *               reached, the pool is closed while waiting, or the waiting coroutine is canceled
     */
    public function get(float $timeout = -1)
    {
        if ($this->pool === null) {
            throw new \RuntimeException('Pool has been closed');
        }
        if ($this->pool->isEmpty() && $this->num < $this->size) {
            $this->make();
        }
        return $this->pool->pop($timeout);
    }

    /**
     * Runs the callback with a connection from the pool, and puts the connection back when the callback is done,
     * whether it returned or threw. A connection taken with get() and not put back stays counted by the pool, and
     * once that has happened as many times as the pool is large, get() finds no connection any more.
     *
     * The connection is put back as it is, also when the callback threw. The pools of the library clean it in put():
     * they roll back a transaction left open, and replace a connection that cannot be cleaned.
     *
     * The callback must not put the connection back itself, nor use it, or anything bound to it, such as a
     * statement, a generator or another coroutine, after it returned: the connection is someone else's by then.
     * A callback that calls withConnection() of the same pool needs a second free connection, or it waits forever;
     * give the inner call a timeout. Code that has to discard a broken connection uses get() and put(null).
     *
     * @template T
     * @param callable(mixed): T $callback called with the connection; what it returns is returned
     * @param float $timeout the number of seconds to wait for a connection; 0 or less waits with no time limit
     * @return T
     * @throws \RuntimeException when no connection is available before the timeout is reached, or the pool is
     *                           closed, or the coroutine is canceled while waiting
     */
    public function withConnection(callable $callback, float $timeout = -1): mixed
    {
        $connection = $this->get($timeout);
        if ($connection === false) {
            // Read before anything yields: close() wakes the coroutines waiting in get() before it drops the channel.
            $errCode = $this->pool?->errCode;
            if ($errCode === null || $errCode === SWOOLE_CHANNEL_CLOSED) {
                throw new \RuntimeException('Pool has been closed');
            }
            if ($errCode === SWOOLE_CHANNEL_CANCELED) {
                throw new \RuntimeException('Canceled while waiting for a connection from the pool');
            }
            throw new \RuntimeException("No connection is available from the pool within {$timeout} seconds");
        }

        try {
            return $callback($connection);
        } finally {
            $this->put($connection);
        }
    }

    public function put(mixed $connection): void
    {
        if ($this->pool === null) {
            return;
        }
        if ($connection !== null) {
            $this->pool->push($connection);
        } else {
            /* connection broken */
            $this->num -= 1;
            $this->make();
        }
    }

    public function close(): void
    {
        if ($this->pool === null) {
            return;
        }
        $this->pool->close();
        $this->pool = null;
        $this->num  = 0;
    }

    protected function make(): void
    {
        $this->num++;
        try {
            if ($this->proxy) {
                $connection = new $this->proxy($this->constructor);
            } else {
                $connection = ($this->constructor)();
            }
        } catch (\Throwable $throwable) {
            $this->num--;
            throw $throwable;
        }
        $this->put($connection);
    }
}
