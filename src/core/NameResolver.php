<?php

namespace Swoole;

use Swoole\NameResolver\Cluster;
use RuntimeException;

abstract class NameResolver
{
    private $filter_fn;

    abstract public function join(string $name, string $ip, int $port, array $options = []): bool;

    abstract public function leave(string $name, string $ip, int $port): bool;

    abstract public function getCluster(string $name): ?Cluster;

    protected $baseUrl;
    protected $prefix;
    protected $info;

    public function __construct($url, $prefix = 'swoole_service_')
    {
        $this->checkServerUrl($url);
        $this->prefix = $prefix;
    }

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
     * !!! The host MUST BE IP ADDRESS
     * @param $url
     */
    protected function checkServerUrl($url)
    {
        $info = parse_url($url);
        if (empty($info['scheme']) or empty($info['host'])) {
            throw new RuntimeException("invalid url parameter '{$url}'");
        }
        if (!filter_var($info['host'], FILTER_VALIDATE_IP)) {
            $info['ip'] = gethostbyname($info['host']);
            if (!filter_var($info['ip'], FILTER_VALIDATE_IP)) {
                throw new RuntimeException("Failed to resolve host '{$info['host']}'");
            }
        } else {
            $info['ip'] = $info['host'];
        }
        $baseUrl = $info['scheme'] . '://' . $info['ip'];
        if (!empty($info['port'])) {
            $baseUrl .= ":{$info['port']}";
        }
        if (!empty($info['path'])) {
            $baseUrl .= rtrim($info['path'], '/');
        }
        $this->baseUrl = $baseUrl;
        $this->info = $info;
    }

    /**
     * return string: final result, non-empty string must be a valid IP address,
     * and an empty string indicates name lookup failed, and lookup operation will not continue.
     * return Cluster: has multiple nodes and failover is possible
     * return false or null: try another name resolver
     * @param string $name
     * @return Cluster|null|false|string
     */
    public function lookup(string $name)
    {
        if ($this->hasFilter() and ($this->getFilter())($name) !== true) {
            return null;
        }
        $cluster = $this->getCluster($name);
        // lookup failed, terminate execution
        if ($cluster == null) {
            return '';
        }
        // only one node, cannot retry
        if ($cluster->count() == 1) {
            return $cluster->pop();
        } else {
            return $cluster;
        }
    }
}
