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
        while ($this->size > $this->num) {
            $this->make();
        }
    }

    /**
     * Get a connection from the pool.
     *
     * @param float $timeout > 0 means waiting for the specified number of seconds. other means no waiting.
     * @return mixed a connection object from the pool, or false if no connection is available before the timeout is reached
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
