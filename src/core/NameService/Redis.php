<?php


namespace Swoole\NameService;

class Redis extends Resolver
{
    private $redis_host;
    private $redis_port;
    private $prefix;

    public function __construct($host, $port, $prefix = 'swoole:service:')
    {
        $this->redis_host = $host;
        $this->redis_port = $port;
        $this->prefix = $prefix;
    }

    protected function connect()
    {
        $redis = new \redis;
        if ($redis->connect($this->redis_host, $this->redis_port) === false) {
            return false;
        }
        return $redis;
    }

    public function join(string $name, string $ip, int $port, array $options = []): bool
    {
        if (($redis = $this->connect()) === false) {
            return false;
        }
        if ($redis->sAdd($this->prefix . $name, $ip . ':' . $port) === false) {
            return false;
        }
        return true;
    }

    public function leave(string $name, string $ip, int $port): bool
    {
        if (($redis = $this->connect()) === false) {
            return false;
        }
        if ($redis->sRem($this->prefix . $name, $ip . ':' . $port) === false) {
            return false;
        }
        return true;
    }

    public function getCluster(string $name): ?Cluster
    {
        if (($redis = $this->connect()) === false) {
            return null;
        }
        $members = $redis->sMembers($this->prefix . $name);
        if (empty($members)) {
            return null;
        }
        $cluster = new Cluster();
        foreach ($members as $m) {
            [$host, $port] = explode(':', $m);
            $cluster->add($host, $port);
        }
        return $cluster;
    }
}
