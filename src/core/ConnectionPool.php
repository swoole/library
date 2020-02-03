<?php

declare(strict_types=1);

namespace Swoole;

use RuntimeException;
use Swoole\Coroutine\Channel;
use Throwable;

class ConnectionPool
{
    public const DEFAULT_SIZE = 64;

    /** @var Channel */
    protected $pool;
    /** @var callable */
    protected $constructor;
    /** @var int */
    protected $size;
    /** @var int */
    protected $num;
    /** @var string|null */
    protected $proxy;

    public function __construct(callable $constructor, int $size = self::DEFAULT_SIZE, ?string $proxy = null)
    {
        $this->pool = new Channel($this->size = $size);
        $this->constructor = $constructor;
        $this->num = 0;
        $this->proxy = $proxy;
    }

    protected function make(): void
    {
        $this->num++;
        try {
            if ($this->proxy) {
                $connection = new $this->proxy($this->constructor);
            } else {
                $constructor = $this->constructor;
                $connection = $constructor();
            }
        } catch (Throwable $throwable) {
            $this->num--;
            throw $throwable;
        }
        $this->put($connection);
    }

    public function fill(): void
    {
        while ($this->size < $this->num) {
            $this->make();
        }
    }

    public function get()
    {
        if ($this->pool === null) {
            throw new RuntimeException('Pool has been closed');
        }
        if ($this->pool->isEmpty() && $this->num < $this->size) {
            $this->make();
        }
        return $this->pool->pop();
    }

    public function put($connection): void
    {
        if ($this->pool === null) {
            return;
        }
        if ($connection !== null) {
            $this->pool->push($connection);
        } else {
            /* connection broken */
            $this->num -= 1;
        }
    }

    public function close(): void
    {
        $this->pool->close();
        $this->pool = null;
        $this->num = 0;
    }
}
