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

/** Nacos 1.x/2.x naming OpenAPI. Nacos 3.x requires a separate protocol adapter. */
class Nacos extends NameResolver
{
    private string $namespace = '';

    private string $group = 'DEFAULT_GROUP';

    private string $cluster = 'DEFAULT';

    private bool $ephemeral = true;

    private ?string $accessToken = null;

    public function withNamespace(string $namespace): self
    {
        $this->namespace = $namespace === 'public' ? '' : $namespace;
        $this->clearCache();
        return $this;
    }

    public function withGroup(string $group): self
    {
        if ($group === '') {
            throw new \InvalidArgumentException('Nacos group must be nonempty');
        }
        $this->group = $group;
        $this->clearCache();
        return $this;
    }

    public function withCluster(string $cluster): self
    {
        if ($cluster === '') {
            throw new \InvalidArgumentException('Nacos cluster must be nonempty');
        }
        $this->cluster = $cluster;
        $this->clearCache();
        return $this;
    }

    public function withEphemeral(bool $ephemeral): self
    {
        $this->ephemeral = $ephemeral;
        $this->clearCache();
        return $this;
    }

    public function withAccessToken(string $token): self
    {
        $this->accessToken = $token;
        $this->clearCache();
        return $this;
    }

    public function join(string $name, string $ip, int $port, array $options = []): bool
    {
        $weight = $options['weight'] ?? 100;
        $this->validateNode($name, $ip, $port, $weight);
        // Scope is resolver-wide so discovery, heartbeat and deregistration address the same instance.
        foreach (['namespaceId' => $this->namespace, 'groupName' => $this->group, 'clusterName' => $this->cluster] as $key => $value) {
            if (isset($options[$key]) && ($options[$key] === 'public' && $key === 'namespaceId' ? '' : $options[$key]) !== $value) {
                throw new \InvalidArgumentException('Configure Nacos scope on the resolver before registering');
            }
        }
        $params = $this->instance($name, $ip, $port) + [
            'weight'  => $weight,
            'healthy' => 'true',
            'enabled' => ($options['enabled'] ?? true) ? 'true' : 'false',
        ];
        if (isset($options['metadata'])) {
            $params['metadata'] = json_encode($options['metadata'], JSON_THROW_ON_ERROR);
        }
        $this->checkMutation($this->nacosRequest('POST', '/nacos/v1/ns/instance', $params));
        $this->clearCache($name);
        return true;
    }

    public function leave(string $name, string $ip, int $port): bool
    {
        $this->validateNode($name, $ip, $port);
        $this->checkMutation($this->nacosRequest('DELETE', '/nacos/v1/ns/instance', $this->instance($name, $ip, $port)));
        $this->clearCache($name);
        return true;
    }

    /** Call at the server-provided interval while an ephemeral instance is serving requests. */
    public function heartbeat(string $name, string $ip, int $port, array $options = []): float
    {
        $weight = $options['weight'] ?? 100;
        $this->validateNode($name, $ip, $port, $weight);
        if (!$this->ephemeral) {
            throw new \LogicException('Persistent Nacos instances do not use client heartbeats');
        }
        $beat = [
            'ip'       => $ip, 'port' => $port, 'serviceName' => $this->group . '@@' . $this->prefix . $name,
            'cluster'  => $this->cluster, 'weight' => $weight,
            'metadata' => (object) ($options['metadata'] ?? []), 'scheduled' => false,
        ];
        $params         = $this->instance($name, $ip, $port);
        $params['beat'] = json_encode($beat, JSON_THROW_ON_ERROR);
        $result         = $this->decodeResponse($this->nacosRequest('PUT', '/nacos/v1/ns/instance/beat', $params));
        if (!$result instanceof \stdClass || (isset($result->code) && $result->code !== 10200)) {
            throw new Exception('Nacos rejected the heartbeat', (int) ($result->code ?? 0));
        }
        $interval = $result->clientBeatInterval ?? 5000;
        if (!is_numeric($interval) || !is_finite((float) $interval) || $interval <= 0) {
            throw new Exception('Nacos returned an invalid heartbeat interval');
        }
        return $interval / 1000;
    }

    public function getCluster(string $name): ?Cluster
    {
        $this->validateName($name);
        $result = $this->decodeResponse($this->nacosRequest('GET', '/nacos/v1/ns/instance/list', [
            'serviceName' => $this->prefix . $name, 'healthyOnly' => 'true', 'clusters' => $this->cluster,
        ]));
        if (!$result instanceof \stdClass || !isset($result->hosts) || !is_array($result->hosts)) {
            throw new Exception('Nacos returned an invalid instance list');
        }
        $cluster = new Cluster();
        foreach ($result->hosts as $node) {
            if (!$node instanceof \stdClass) {
                throw new Exception('Nacos returned an invalid instance');
            }
            if (($node->healthy ?? false) !== true || ($node->enabled ?? false) !== true) {
                continue;
            }
            if (!isset($node->ip, $node->port, $node->weight) || !is_string($node->ip) || !is_int($node->port)
                || (!is_int($node->weight) && !is_float($node->weight))) {
                throw new Exception('Nacos returned an invalid instance endpoint');
            }
            $cluster->add($node->ip, $node->port, $node->weight);
        }
        return $cluster->count() === 0 ? null : $cluster;
    }

    protected function prepareRegistrationOptions(string $name, string $ip, int $port, array $options, float $interval): array
    {
        if (!$this->ephemeral) {
            throw new \LogicException('Managed Nacos registration requires ephemeral instances');
        }
        return $options;
    }

    private function instance(string $name, string $ip, int $port): array
    {
        return ['serviceName' => $this->prefix . $name, 'ip' => $ip, 'port' => $port,
            'clusterName'     => $this->cluster, 'ephemeral' => $this->ephemeral ? 'true' : 'false'];
    }

    private function checkMutation(ClientProxy $response): void
    {
        $this->checkResponse($response);
        if (trim($response->getBody()) !== 'ok') {
            throw new Exception('Nacos rejected the instance operation');
        }
    }

    private function nacosRequest(string $method, string $path, array $params): ClientProxy
    {
        $params += ['namespaceId' => $this->namespace, 'groupName' => $this->group];
        if ($this->accessToken !== null) {
            $params['accessToken'] = $this->accessToken;
        }
        return $this->request($method, $path, null, $params);
    }
}
