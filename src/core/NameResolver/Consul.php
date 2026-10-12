<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\NameResolver;

use Swoole\Coroutine\Http\ClientProxy;
use Swoole\NameResolver;

class Consul extends NameResolver
{
    private ?string $token = null;

    private string $datacenter = '';

    private string $namespace = '';

    public function withToken(string $token): self
    {
        $this->token = $token;
        $this->clearCache();
        return $this;
    }

    public function withDatacenter(string $datacenter): self
    {
        $this->datacenter = $datacenter;
        $this->clearCache();
        return $this;
    }

    public function withNamespace(string $namespace): self
    {
        $this->namespace = $namespace;
        $this->clearCache();
        return $this;
    }

    public function join(string $name, string $ip, int $port, array $options = []): bool
    {
        $weight = $options['weight'] ?? 100;
        $this->validateNode($name, $ip, $port, $weight);
        if (!is_int($weight)) {
            throw new \InvalidArgumentException('Consul weights must be integers');
        }
        $data = [
            'ID'                => $this->getServiceId($name, $ip, $port),
            'Name'              => $this->prefix . $name,
            'Address'           => $ip,
            'Port'              => $port,
            'EnableTagOverride' => false,
            'Weights'           => ['Passing' => $weight, 'Warning' => 0],
        ];
        foreach (['tags' => 'Tags', 'meta' => 'Meta', 'check' => 'Check', 'checks' => 'Checks'] as $option => $field) {
            if (isset($options[$option])) {
                $data[$field] = $options[$option];
            }
        }
        $this->checkResponse($this->consulRequest('PUT', '/v1/agent/service/register', json_encode($data, JSON_THROW_ON_ERROR)));
        $this->clearCache($name);
        return true;
    }

    public function leave(string $name, string $ip, int $port): bool
    {
        $this->validateNode($name, $ip, $port);
        $this->checkResponse($this->consulRequest('PUT', '/v1/agent/service/deregister/' . rawurlencode($this->getServiceId($name, $ip, $port))));
        $this->clearCache($name);
        return true;
    }

    public function enableMaintenanceMode(string $name, string $ip, int $port, bool $enable = true, string $reason = ''): bool
    {
        $this->validateNode($name, $ip, $port);
        $this->checkResponse($this->consulRequest('PUT', '/v1/agent/service/maintenance/' . rawurlencode($this->getServiceId($name, $ip, $port)), null,
            ['enable' => $enable ? 'true' : 'false', 'reason' => $reason]));
        $this->clearCache($name);
        return true;
    }

    /** Renew the default TTL check, or the explicit checkId supplied in options. */
    public function heartbeat(string $name, string $ip, int $port, array $options = []): bool
    {
        $this->validateNode($name, $ip, $port);
        $this->updateHealth($options['checkId'] ?? 'service:' . $this->getServiceId($name, $ip, $port), 'passing', $options['note'] ?? '');
        $this->clearCache($name);
        return true;
    }

    public function updateHealth(string $checkId, string $status, string $output = ''): bool
    {
        if ($checkId === '' || !in_array($status, ['passing', 'warning', 'critical'], true)) {
            throw new \InvalidArgumentException('A check ID and a valid Consul health status are required');
        }
        $this->checkResponse($this->consulRequest('PUT', '/v1/agent/check/update/' . rawurlencode($checkId),
            json_encode(['Status' => $status, 'Output' => $output], JSON_THROW_ON_ERROR)));
        $this->clearCache();
        return true;
    }

    public function getCluster(string $name): ?Cluster
    {
        $this->validateName($name);
        $list = $this->decodeResponse($this->consulRequest('GET', '/v1/health/service/' . rawurlencode($this->prefix . $name), null, ['passing' => 'true']));
        if (!is_array($list) || !array_is_list($list)) {
            throw new Exception('Consul returned an invalid service list');
        }
        $cluster = new Cluster();
        foreach ($list as $entry) {
            if (!$entry instanceof \stdClass || !isset($entry->Service) || !$entry->Service instanceof \stdClass) {
                throw new Exception('Consul returned an invalid service entry');
            }
            if (isset($entry->Checks) && !is_array($entry->Checks)) {
                throw new Exception('Consul returned invalid health checks');
            }
            foreach ($entry->Checks ?? [] as $check) {
                if (!$check instanceof \stdClass || ($check->Status ?? '') !== 'passing') {
                    continue 2;
                }
            }
            $service = $entry->Service;
            $host    = $service->Address ?? '';
            if ($host === '') {
                $host = $entry->Node->Address ?? '';
            }
            if (!is_string($host) || !isset($service->Port) || !is_int($service->Port)) {
                throw new Exception('Consul returned an invalid service endpoint');
            }
            $weight = $service->Weights->Passing ?? 100;
            if (!is_int($weight)) {
                throw new Exception('Consul returned an invalid service weight');
            }
            $cluster->add($host, $service->Port, $weight);
        }
        return $cluster->count() === 0 ? null : $cluster;
    }

    protected function prepareRegistrationOptions(string $name, string $ip, int $port, array $options, float $interval): array
    {
        $ttl = $options['ttl'] ?? max(3 * $interval, $interval + 2 * $this->timeout);
        if (!is_numeric($ttl) || !is_finite((float) $ttl) || $ttl <= $interval + $this->timeout) {
            throw new \InvalidArgumentException('Managed Consul TTL must exceed heartbeat interval plus registry timeout');
        }
        $options['checkId'] ??= 'service:' . $this->getServiceId($name, $ip, $port) . ':heartbeat';
        $checks = $options['checks'] ?? [];
        if (isset($options['check'])) {
            $checks[] = $options['check'];
            unset($options['check']);
        }
        $checks[] = ['CheckID' => $options['checkId'], 'Name' => 'Swoole service heartbeat',
            'TTL'              => $ttl . 's', 'Status' => 'passing', 'DeregisterCriticalServiceAfter' => '1m'];
        $options['checks'] = $checks;
        return $options;
    }

    private function consulRequest(string $method, string $path, mixed $body = null, array $query = []): ClientProxy
    {
        if ($this->namespace !== '') {
            $query['ns'] = $this->namespace;
        }
        if ($this->datacenter !== '' && $method === 'GET') {
            $query['dc'] = $this->datacenter;
        }
        $headers = ['Content-Type' => 'application/json'];
        if ($this->token !== null) {
            $headers['X-Consul-Token'] = $this->token;
        }
        return $this->request($method, $path, $body, $query, $headers);
    }

    private function getServiceId(string $name, string $ip, int $port): string
    {
        return $this->prefix . $name . "_{$ip}:{$port}";
    }
}
