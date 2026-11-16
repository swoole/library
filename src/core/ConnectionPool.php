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

    /** @var \WeakMap<object, bool> true while checked out, false while queued */
    private \WeakMap $connections;

    /** @var callable|null */
    private $destructor;

    /**
     * @param callable(): object $constructor Creates a distinct connection object.
     * @param (callable(object): void)|null $destructor Releases a discarded or idle connection; active loans are
     *                                                  released when returned after close().
     */
    public function __construct(callable $constructor, protected int $size = self::DEFAULT_SIZE, protected ?string $proxy = null, ?callable $destructor = null)
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('Connection pool size must be positive');
        }
        $this->connections = new \WeakMap();
        $this->destructor  = $destructor;
        $this->pool        = new Channel($this->size);
        $this->constructor = $constructor;
    }

    public function fill(): void
    {
        if ($this->pool === null) {
            // A closed pool has nowhere to keep the connections: they would be made, counted and dropped.
            return;
        }
        while ($this->pool !== null && $this->size > $this->num) {
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
        $connection = $this->pool?->pop($timeout) ?? false;
        if (is_object($connection)) {
            $this->connections[$connection] = true;
        }
        return $connection;
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
     * give the inner call a timeout. Code that has to discard a broken connection uses get() and discard().
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
            throw new \RuntimeException(sprintf('No connection is available from the pool within %g seconds', $timeout));
        }

        try {
            return $callback($connection);
        } finally {
            $this->put($connection);
        }
    }

    /**
     * Return a checked-out connection. Foreign or duplicate returns throw before changing the pool.
     * A null return is supported only when exactly one lost or checked-out connection can be identified;
     * use discard($connection) when several connections are borrowed.
     */
    public function put(mixed $connection): void
    {
        if ($this->pool === null) {
            if (is_object($connection) && isset($this->connections[$connection])) {
                unset($this->connections[$connection]);
                $this->dispose($connection);
            }
            return;
        }
        if ($connection === null) {
            $borrowed = [];
            foreach ($this->connections as $object => $checkedOut) {
                if ($checkedOut) {
                    $borrowed[] = $object;
                }
            }
            if (count($borrowed) === 1 && $this->num === count($this->connections)) {
                $this->discard($borrowed[0]);
                return;
            }
            // A borrower may already have released its last reference to the broken object.
            if ($borrowed === [] && $this->num - count($this->connections) === 1) {
                $this->num--;
                $this->make();
                return;
            }
            throw new \InvalidArgumentException('Cannot identify the broken connection; use discard($connection)');
        }
        $this->assertCanReturn($connection);
        $this->connections[$connection] = false;
        $this->pool->push($connection);
    }

    /**
     * Discard this checked-out connection and replace it. Unlike put(null), this also works with multiple borrowers.
     */
    public function discard(object $connection): void
    {
        $this->assertCanReturn($connection);
        unset($this->connections[$connection]);
        if ($this->pool === null) {
            $this->dispose($connection);
            return;
        }
        $this->num--;
        try {
            $this->dispose($connection);
        } finally {
            if ($this->pool !== null && $this->num < $this->size) {
                $this->make();
            }
        }
    }

    public function close(): void
    {
        if ($this->pool === null) {
            return;
        }
        $pool       = $this->pool;
        $this->pool = null;
        $this->num  = 0;
        $failure    = null;
        try {
            while (!$pool->isEmpty()) {
                $connection = $pool->pop();
                unset($this->connections[$connection]);
                try {
                    $this->dispose($connection);
                } catch (\Throwable $e) {
                    $failure = $failure ?? $e;
                }
            }
        } finally {
            $pool->close();
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    protected function assertCanReturn(mixed $connection): void
    {
        if (!is_object($connection) || !isset($this->connections[$connection])) {
            throw new \InvalidArgumentException('The connection does not belong to this pool');
        }
        if (!$this->connections[$connection]) {
            throw new \LogicException('The connection has already been returned to this pool');
        }
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
            if ($this->pool !== null) {
                $this->num--;
            }
            throw $throwable;
        }
        if (!is_object($connection) || isset($this->connections[$connection])) {
            if ($this->pool !== null) {
                $this->num--;
            }
            throw new \UnexpectedValueException('The connection constructor must return a new object');
        }
        $this->connections[$connection] = true;
        $this->put($connection);
    }

    private function dispose(object $connection): void
    {
        if ($this->destructor !== null) {
            ($this->destructor)($connection);
        }
    }
}
