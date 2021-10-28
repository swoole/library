<?php

namespace Swoole\NameService;

abstract class Resolver
{
    private $filter_fn;

    abstract public function join(string $name, string $ip, int $port, array $options = []): bool;

    abstract public function leave(string $name, string $ip, int $port): bool;

    abstract public function getCluster(string $name): ?Cluster;

    public function withFilter(callable $fn): self
    {
        $this->filter_fn = $fn;
        return $this;
    }

    function resolve(string $name): ?Cluster
    {
        if ($this->hasFilter() and ($this->getFilter())($name) !== true) {
            return null;
        }
        return $this->getCluster($name);
    }

    public function getFilter()
    {
        return $this->filter_fn;
    }

    public function hasFilter(): bool
    {
        return !empty($this->filter_fn);
    }
}
