<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

namespace Swoole\NameResolver;

use Swoole\NameResolver;

class Redis extends NameResolver
{
    private $serverHost;
    private $serverPort;
    private $prefix;

    public function __construct($host, $port, $prefix = 'swoole:service:')
    {
        // The host MUST BE IP ADDRESS
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $host = gethostbyname($host);
        }
        $this->serverHost = $host;
        $this->serverPort = $port;
        $this->prefix = $prefix;
    }

    protected function connect()
    {
        $redis = new \redis;
        if ($redis->connect($this->serverHost, $this->serverPort) === false) {
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
