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

    public function getFilter()
    {
        return $this->filter_fn;
    }

    public function hasFilter(): bool
    {
        return !empty($this->filter_fn);
    }

    /**
     * return string: final result, non-empty string must be a valid IP address,
     * and an empty string indicates name lookup failed, and lookup operation will not continue.
     * return Cluster: has multiple nodes and failover is possible
     * return false or null: try another name resolver
     * @param string $name
     * @return Cluster|null|false|string
     */
    function resolve(string $name)
    {
        if ($this->hasFilter() and ($this->getFilter())($name) !== true) {
            return null;
        }
        return $this->getCluster($name);
    }
}
