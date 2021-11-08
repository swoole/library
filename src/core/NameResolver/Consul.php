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

use function  Swoole\Coroutine\Http\request;
use function  Swoole\Coroutine\Http\get;

class Consul extends NameResolver
{
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
        $r = request($this->baseUrl . '/v1/agent/service/register', 'PUT', json_encode($data));
        return $r and $r->getStatusCode() === 200;
    }

    public function leave(string $name, string $ip, int $port): bool
    {
        $r = request(
            $this->baseUrl . '/v1/agent/service/deregister/' . $this->getServiceId(
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
        $r = request(
            $this->baseUrl . '/v1/agent/service/maintenance/' . $this->getServiceId(
                $name,
                $ip,
                $port
            ),
            'PUT'
        );
        return $r and $r->getStatusCode() === 200;
    }

    public function getCluster(string $name): ?Cluster
    {
        $r = get($this->baseUrl . '/v1/catalog/service/' . $this->prefix . $name);
        if (!$r or $r->getStatusCode() !== 200) {
            return null;
        }
        $list = json_decode($r->getBody());
        if (empty($list)) {
            return null;
        }
        $cluster = new Cluster();
        foreach ($list as $li) {
            $cluster->add($li->ServiceAddress, $li->ServicePort, $li->ServiceWeights->Passing);
        }
        return $cluster;
    }
}
