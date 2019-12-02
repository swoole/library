<?php
declare(strict_types=1);

namespace Swoole;

use Swoole\Coroutine\Channel;

class ConnectionPool
{
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

    public function __construct(callable $constructor, int $size = 64, ?string $proxy = null)
    {
        $this->pool = new Channel($this->size = $size);
        $this->constructor = $constructor;
        $this->num = 0;
        $this->proxy = $proxy;
    }

    protected function make(): void
    {
        $this->num++;
        if ($this->proxy) {
            $connection = new $this->proxy($this->constructor);
        } else {
            $constructor = $this->constructor;
            $connection = $constructor();
        }
        $this->put($connection);
    }

    public function fill(): void
    {
        for ($n = $this->size - $this->num; $n--;) {
            $this->make();
        }
    }

    public function get()
    {
        if ($this->pool->isEmpty() && $this->num < $this->size) {
            $this->make();
        }
        return $this->pool->pop();
    }

    public function put($connection): void
    {
        $this->pool->push($connection);
    }

    public function close(): void
    {
        $this->pool->close();
        $this->pool = null;
    }
}
