<?php


namespace Swoole\NameService;

class Redis extends BaseObject
{
    private $redis_host;
    private $redis_port;
    private $prefix;

    public function __construct($host, $port, $prefix = 'swoole:nameserver:')
    {
        $this->redis_host = $host;
        $this->redis_port = $port;
        $this->prefix = $prefix;
    }

    protected function connect() {
        $redis = new \redis;
        if ($redis->connect($this->redis_host, $this->redis_port) === false) {
            return false;
        }
        return $redis;
    }

    public function join(string $name, string $ip, int $port): bool
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

    public function resolve(string $name)
    {
        if (($redis = $this->connect()) === false) {
            return false;
        }
        $members = $redis->sMembers($this->prefix . $name);
        if ($members === false) {
            return false;
        }
        return new Cluster($members);
    }
}
