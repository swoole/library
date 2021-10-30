<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

namespace Swoole\NameResolver;

use Swoole\Coroutine;

class Nacos extends Resolver
{
    private $server;
    private $prefix;

    public function __construct($server, $prefix = 'swoole_service_')
    {
        $this->server = $server;
        $this->prefix = $prefix;
    }

    public function join(string $name, string $ip, int $port, array $options = []): bool
    {
        $params['port'] = $port;
        $params['ip'] = $ip;
        $params['healthy'] = 'true';
        $params['weight'] = $options['weight'] ?? 100;
        $params['encoding'] = $options['encoding'] ?? 'utf-8';
        $params['namespaceId'] = $options['namespaceId'] ?? 'public';
        $params['serviceName'] = $this->prefix . $name;

        $r = Coroutine\Http\post($this->server . '/nacos/v1/ns/instance?' . http_build_query($params), []);
        return $r and $r->getStatusCode() === 200;
    }

    public function leave(string $name, string $ip, int $port): bool
    {
        $params['port'] = $port;
        $params['ip'] = $ip;
        $params['serviceName'] = $this->prefix . $name;

        $r = Coroutine\Http\request($this->server . '/nacos/v1/ns/instance?' . http_build_query($params), 'DELETE');
        return $r and $r->getStatusCode() === 200;
    }

    public function getCluster(string $name): ?Cluster
    {
        $params['serviceName'] = $this->prefix . $name;

        $r = Coroutine\Http\get($this->server . '/nacos/v1/ns/instance/list?' . http_build_query($params));
        if (!$r or $r->getStatusCode() !== 200) {
            return null;
        }
        $result = json_decode($r->getBody());
        $cluster = new Cluster();
        foreach ($result->hosts as $node) {
            $cluster->add($node->ip, $node->port, $node->weight);
        }
        return $cluster;
    }
}
