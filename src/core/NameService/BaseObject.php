<?php

namespace Swoole\NameService;

abstract class BaseObject
{
    private $filter_fn;

    abstract public function resolve(string $name);
    abstract public function join(string $name, string $ip, int $port): bool;
    abstract public function leave(string $name, string $ip, int $port): bool;

    public function withFilter(callable $fn) {
        $this->filter_fn = $fn;
    }

    public function getFilter() {
        return $this->filter_fn;
    }

    public function hasFilter() : bool {
        return !empty($this->filter_fn);
    }
}
