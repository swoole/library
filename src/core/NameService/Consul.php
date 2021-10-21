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

class Consul extends BaseObject
{
    private $server;
    private $prefix;

    public function __construct($server, $prefix = 'swoole_service_')
    {
        $this->server = $server;
        $this->prefix = $prefix;
    }

    private function getServiceId(string $name, string $ip, int $port): string
    {
        return $this->prefix . $name . "_{$ip}:{$port}";
    }

    public function join(string $name, string $ip, int $port, array $options = []): bool
    {
        $weight = $options['weight'] ?? 100;
        $data = [
            "ID" => $this->getServiceId($name, $ip, $port),
            "Name" => $this->prefix . $name,
            "Address" => $ip,
            "Port" => $port,
            "EnableTagOverride" => false,
            "Weights" => [
                "Passing" => $weight,
                "Warning" => 1,
            ]
        ];
        $r = Coroutine\Http\request($this->server . '/v1/agent/service/register', 'PUT', json_encode($data));
        return $r and $r->getStatusCode() === 200;
    }

    public function leave(string $name, string $ip, int $port): bool
    {
        $r = Coroutine\Http\request(
            $this->server . '/v1/agent/service/deregister/' . $this->getServiceId(
                $name,
                $ip,
                $port
            ),
            'PUT'
        );
        return $r and $r->getStatusCode() === 200;
    }

    public function enableMaintenanceMode(string $name, string $ip, int $port): bool
    {
        $r = Coroutine\Http\request(
            $this->server . '/v1/agent/service/maintenance/' . $this->getServiceId(
                $name,
                $ip,
                $port
            ),
            'PUT'
        );
        return $r and $r->getStatusCode() === 200;
    }

    public function resolve(string $name)
    {
        $r = Coroutine\Http\get($this->server . '/v1/catalog/service/' . $this->prefix . $name);
        if ($r and $r->getStatusCode() === 200) {
            $list = json_decode($r->getBody());
            $result = [];
            foreach ($list as $li) {
                $result[] = [
                    'host' => $li->ServiceAddress,
                    'port' => $li->ServicePort,
                    'data' => $li,
                ];
            }
            return $result;
        }
        return false;
    }
}
