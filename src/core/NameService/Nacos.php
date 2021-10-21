<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

namespace Swoole\NameService;

use Swoole\Coroutine;

class Nacos extends BaseObject
{
    private $server;
    private $prefix;

    public function __construct($server, $service_prefix = 'swoole_service_')
    {
        $this->server = $server;
        $this->prefix = $service_prefix;
    }

    private function getServiceId(string $name, string $ip, int $port): string
    {
        return $this->prefix . $name . "_{$ip}:{$port}";
    }

    public function join(string $name, string $ip, int $port, array $options = []): bool
    {
        $params['port'] = $port;
        $params['ip'] = $ip;
        $params['healthy'] = 'true';
        $params['weight'] = $options['weight'] ?? 1.0;
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

    public function resolve(string $name)
    {
        $params['serviceName'] = $this->prefix . $name;

        $r = Coroutine\Http\get($this->server . '/nacos/v1/ns/instance/list?' . http_build_query($params));
        if (!$r or $r->getStatusCode() !== 200) {
            return false;
        }
        $nodes = [];
        $result = json_decode($r->getBody());
        foreach ($result->hosts as $node) {
            $nodes[] = [
                'host' => $node->ip,
                'port' => $node->port,
                'weight' => $node->weight,
                'data' => $node,
            ];
        }
        return $nodes;
    }
}
