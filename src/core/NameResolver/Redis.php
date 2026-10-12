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

use Swoole\NameResolver;

class Redis extends NameResolver
{
    protected const SCHEMES = ['tcp', 'redis', 'rediss'];

    private float $ttl = 0;

    private array $tlsOptions = [];

    public function __construct(string $url, string $prefix = 'swoole:service:')
    {
        parent::__construct($url, $prefix);
        if (!in_array($this->info['scheme'], ['tcp', 'redis', 'rediss'], true)) {
            throw new \InvalidArgumentException('Redis registry requires a tcp, redis or rediss URL');
        }
        $database = trim($this->info['path'] ?? '', '/');
        if ($database !== '' && !ctype_digit($database)) {
            throw new \InvalidArgumentException('Redis URL path must be a database number');
        }
    }

    /** Zero retains persistent registration compatibility; a positive TTL requires periodic heartbeat(). */
    public function withTtl(float $seconds): self
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw new \InvalidArgumentException('Redis registration TTL must be finite and nonnegative');
        }
        $this->ttl = $seconds;
        return $this;
    }

    public function withTlsOptions(array $options): self
    {
        $this->tlsOptions = $options;
        return $this;
    }

    public function join(string $name, string $ip, int $port, array $options = []): bool
    {
        $weight = $options['weight'] ?? 100;
        $this->validateNode($name, $ip, $port, $weight);
        $ttl = $options['ttl'] ?? $this->ttl;
        if (!is_numeric($ttl) || !is_finite((float) $ttl) || $ttl < 0 || $ttl > PHP_INT_MAX / 1000) {
            throw new \InvalidArgumentException('Redis registration TTL must be finite and nonnegative');
        }
        $script = <<<'LUA'
local now = redis.call('TIME')
local expires = 0
if tonumber(ARGV[3]) > 0 then
    expires = tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000) + tonumber(ARGV[3])
end
for _, member in ipairs(redis.call('SMEMBERS', KEYS[1])) do
    local matches = member == ARGV[1] .. ':' .. ARGV[2]
    if string.sub(member, 1, 1) == '{' then
        local ok, node = pcall(cjson.decode, member)
        matches = ok and type(node) == 'table' and node.host == ARGV[1] and node.port == tonumber(ARGV[2])
    end
    if matches then redis.call('SREM', KEYS[1], member) end
end
redis.call('SADD', KEYS[1], cjson.encode({host=ARGV[1], port=tonumber(ARGV[2]), weight=tonumber(ARGV[4]), expires=expires}))
return 1
LUA;
        $this->execute($script, $name, [$ip, $port, (int) ceil((float) $ttl * 1000), $weight]);
        $this->clearCache($name);
        return true;
    }

    public function heartbeat(string $name, string $ip, int $port, array $options = []): bool
    {
        return $this->join($name, $ip, $port, $options);
    }

    public function leave(string $name, string $ip, int $port): bool
    {
        $this->validateNode($name, $ip, $port);
        $script = <<<'LUA'
for _, member in ipairs(redis.call('SMEMBERS', KEYS[1])) do
    local matches = member == ARGV[1] .. ':' .. ARGV[2]
    if string.sub(member, 1, 1) == '{' then
        local ok, node = pcall(cjson.decode, member)
        matches = ok and type(node) == 'table' and node.host == ARGV[1] and node.port == tonumber(ARGV[2])
    end
    if matches then redis.call('SREM', KEYS[1], member) end
end
return 1
LUA;
        $this->execute($script, $name, [$ip, $port]);
        $this->clearCache($name);
        return true;
    }

    public function getCluster(string $name): ?Cluster
    {
        $this->validateName($name);
        $script = <<<'LUA'
local now = redis.call('TIME')
local timestamp = tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000)
local result = {}
for _, member in ipairs(redis.call('SMEMBERS', KEYS[1])) do
    local expired = false
    if string.sub(member, 1, 1) == '{' then
        local ok, node = pcall(cjson.decode, member)
        local expires = ok and type(node) == 'table' and tonumber(node.expires)
        expired = expires and expires > 0 and expires <= timestamp
    end
    if expired then redis.call('SREM', KEYS[1], member)
    else table.insert(result, member) end
end
return result
LUA;
        $members = $this->execute($script, $name);
        if (!is_array($members)) {
            throw new Exception('Redis returned an invalid service list');
        }
        $cluster = new Cluster();
        foreach ($members as $member) {
            if (is_string($member) && str_starts_with($member, '{')) {
                try {
                    $node = json_decode($member, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new Exception('Redis contains an invalid service endpoint', 0, $e);
                }
                if (!isset($node['host'], $node['port'], $node['weight'], $node['expires']) || !is_string($node['host']) || !is_int($node['port'])
                    || (!is_int($node['weight']) && !is_float($node['weight'])) || !is_numeric($node['expires'])) {
                    throw new Exception('Redis contains an invalid service endpoint');
                }
                $cluster->add($node['host'], $node['port'], $node['weight']);
                continue;
            }
            $delimiter = is_string($member) ? strrpos($member, ':') : false;
            if ($delimiter === false || !ctype_digit(substr($member, $delimiter + 1))) {
                throw new Exception('Redis contains an invalid service endpoint');
            }
            $cluster->add(trim(substr($member, 0, $delimiter), '[]'), (int) substr($member, $delimiter + 1));
        }
        return $cluster->count() === 0 ? null : $cluster;
    }

    protected function prepareRegistrationOptions(string $name, string $ip, int $port, array $options, float $interval): array
    {
        $ttl = $options['ttl'] ?? ($this->ttl > 0 ? $this->ttl : max(3 * $interval, $interval + 2 * $this->timeout));
        if (!is_numeric($ttl) || !is_finite((float) $ttl) || $ttl <= $interval + $this->timeout) {
            throw new \InvalidArgumentException('Managed Redis TTL must exceed heartbeat interval plus registry timeout');
        }
        $options['ttl'] = (float) $ttl;
        return $options;
    }

    protected function connect(): \Redis
    {
        $redis = new \Redis();
        try {
            $tls  = $this->info['scheme'] === 'rediss';
            $host = $this->getServerIp();
            if (str_contains($host, ':')) {
                $host = '[' . $host . ']';
            }
            if ($tls) {
                $host = 'tls://' . $host;
            }
            $context = $tls ? ['stream' => $this->tlsOptions + [
                'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->info['host'],
            ]] : null;
            if (!$redis->connect($host, $this->info['port'] ?? 6379, $this->timeout, null, 0, $this->timeout, $context)) {
                throw new Exception('Failed to connect to the Redis registry');
            }
            if (isset($this->info['pass']) || isset($this->info['user'])) {
                $password    = rawurldecode($this->info['pass'] ?? $this->info['user']);
                $credentials = isset($this->info['pass']) && ($this->info['user'] ?? '') !== ''
                    ? [rawurldecode($this->info['user']), $password] : $password;
                if (!$redis->auth($credentials)) {
                    throw new Exception('Redis registry authentication failed');
                }
            }
            $database = (int) trim($this->info['path'] ?? '0', '/');
            if ($database !== 0 && !$redis->select($database)) {
                throw new Exception('Failed to select the Redis registry database');
            }
            return $redis;
        } catch (\Throwable $e) {
            if ($redis->isConnected()) {
                $redis->close();
            }
            throw new Exception('Redis registry connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    private function execute(string $script, string $name, array $arguments = []): mixed
    {
        $redis = $this->connect();
        try {
            $key    = $this->prefix . $name;
            $result = $redis->eval($script, [$key, ...$arguments], 1);
            if ($result === false) {
                throw new Exception('Redis registry operation failed');
            }
            return $result;
        } catch (\RedisException $e) {
            throw new Exception('Redis registry operation failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        } finally {
            $redis->close();
        }
    }
}
