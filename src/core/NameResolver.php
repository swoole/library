<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\ClientProxy;
use Swoole\NameResolver\Cluster;
use Swoole\NameResolver\Exception;
use Swoole\NameResolver\Registration;

abstract class NameResolver
{
    protected const SCHEMES = ['http', 'https'];

    protected $baseUrl;

    protected $info;

    protected float $timeout = 3.0;

    private $filter_fn;

    private float $cacheTtl = 1.0;

    private float $negativeTtl = 0.1;

    private int $cacheCapacity = 1024;

    private array $cache = [];

    private array $pending = [];

    private int $generation = 0;

    private array $httpOptions = [];

    public function __construct(string $url, protected $prefix = 'swoole_service_')
    {
        $this->checkServerUrl($url);
    }

    public function __clone()
    {
        $this->cache   = [];
        $this->pending = [];
        $this->generation++;
    }

    abstract public function join(string $name, string $ip, int $port, array $options = []): bool;

    abstract public function leave(string $name, string $ip, int $port): bool;

    abstract public function getCluster(string $name): ?Cluster;

    /** Start a managed registration in the current coroutine/event loop. */
    public function register(string $name, string $ip, int $port, array $options = [], float $interval = 5.0): Registration
    {
        $this->validateNode($name, $ip, $port, $options['weight'] ?? 100);
        if (!is_finite($interval) || $interval <= 0 || $interval > 86400) {
            throw new \InvalidArgumentException('Heartbeat interval must be finite and between zero and one day');
        }
        return new Registration($this, $name, $ip, $port, $this->prepareRegistrationOptions($name, $ip, $port, $options, $interval), $interval);
    }

    /** Custom backends may renew a lease instead of registering again. */
    public function heartbeat(string $name, string $ip, int $port, array $options = [])
    {
        return $this->join($name, $ip, $port, $options);
    }

    public function withFilter(callable $fn): self
    {
        $this->filter_fn = $fn;
        return $this;
    }

    public function getFilter(): ?callable
    {
        return $this->filter_fn;
    }

    public function hasFilter(): bool
    {
        return $this->filter_fn !== null;
    }

    public function withTimeout(float $seconds): self
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new \InvalidArgumentException('Resolver timeout must be finite and greater than zero');
        }
        $this->timeout = $seconds;
        return $this;
    }

    /** Positive/negative cache lifetimes in seconds. Zero disables that cache. */
    public function withCache(float $ttl, int $capacity = 1024, float $negativeTtl = 0.1): self
    {
        if (!is_finite($ttl) || $ttl < 0 || !is_finite($negativeTtl) || $negativeTtl < 0 || $capacity < 1) {
            throw new \InvalidArgumentException('Cache lifetimes must be finite and nonnegative, capacity positive');
        }
        $this->cacheTtl      = $ttl;
        $this->negativeTtl   = $negativeTtl;
        $this->cacheCapacity = $capacity;
        $this->clearCache();
        return $this;
    }

    public function clearCache(?string $name = null): void
    {
        $this->generation++;
        if ($name === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$name]);
        }
    }

    /** TLS options for the registry connection, e.g. ssl_cafile and ssl_cert_file. */
    public function withHttpOptions(array $options): self
    {
        $this->httpOptions = $options;
        $this->clearCache();
        return $this;
    }

    /** null skips this resolver; '' stops resolution; a node/Cluster contains service endpoints. */
    public function lookup(string $name)
    {
        if ($this->filter_fn !== null && ($this->filter_fn)($name) !== true) {
            return null;
        }
        $this->validateName($name);
        try {
            $cluster = $this->resolveCluster($name);
        } catch (Exception $e) {
            // An unscoped resolver must not break ordinary DNS during a registry outage.
            if ($this->filter_fn === null) {
                return null;
            }
            throw $e;
        }
        if ($cluster === null || $cluster->count() === 0) {
            return $this->filter_fn === null ? null : '';
        }
        return $cluster->count() === 1 ? $cluster->pop() : $cluster;
    }

    protected function validateName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Service name must be nonempty and contain no null bytes');
        }
    }

    protected function validateNode(string $name, string $ip, int $port, int|float $weight = 100): void
    {
        $this->validateName($name);
        (new Cluster())->add($ip, $port, $weight);
    }

    protected function checkServerUrl(string $url): void
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \InvalidArgumentException('Registry URL contains invalid characters');
        }
        $info = parse_url($url);
        if ($info === false || empty($info['scheme']) || empty($info['host']) || isset($info['query']) || isset($info['fragment'])) {
            throw new \InvalidArgumentException('Invalid registry URL');
        }
        $info['scheme'] = strtolower($info['scheme']);
        $info['host']   = trim($info['host'], '[]');
        if (!in_array($info['scheme'], static::SCHEMES, true)) {
            throw new \InvalidArgumentException('Unsupported registry URL scheme');
        }
        if (isset($info['port']) && $info['port'] < 1) {
            throw new \InvalidArgumentException('Invalid registry port');
        }
        $host          = str_contains($info['host'], ':') ? '[' . $info['host'] . ']' : $info['host'];
        $this->baseUrl = $info['scheme'] . '://' . $host . (isset($info['port']) ? ':' . $info['port'] : '')
            . rtrim($info['path'] ?? '', '/');
        $this->info = $info;
    }

    /** Resolve through DNS directly, never through the service resolver chain itself. */
    protected function getServerIp(): string
    {
        $host = $this->info['host'];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        $resolve = function () use ($host): string {
            $ip = Coroutine\System::gethostbyname($host, AF_INET, $this->timeout);
            if (!$ip) {
                $ip = Coroutine\System::gethostbyname($host, AF_INET6, $this->timeout);
            }
            return $ip ?: '';
        };
        $ip = '';
        if (Coroutine::getCid() >= 0) {
            $ip = $resolve();
        } else {
            $ip = gethostbyname($host);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $records = @dns_get_record($host, DNS_AAAA);
                $ip      = $records[0]['ipv6'] ?? '';
            }
        }
        if ($ip === '') {
            throw new Exception("Failed to resolve registry host '{$host}'");
        }
        return $ip;
    }

    protected function request(string $method, string $path, mixed $body = null, array $query = [], array $headers = []): ClientProxy
    {
        if (!in_array($this->info['scheme'], ['http', 'https'], true)) {
            throw new \InvalidArgumentException('HTTP registry requires an http or https URL');
        }
        $operation = function () use ($method, $path, $body, $query, $headers): ClientProxy {
            $tls    = $this->info['scheme'] === 'https';
            $port   = $this->info['port'] ?? ($tls ? 443 : 80);
            $client = new Client($this->getServerIp(), $port, $tls);
            try {
                $client->set($this->httpOptions + [
                    'timeout'              => $this->timeout,
                    'connect_timeout'      => $this->timeout,
                    'ssl_host_name'        => $this->info['host'],
                    'ssl_verify_peer'      => true,
                    'ssl_verify_host'      => true,
                ]);
                $host = str_contains($this->info['host'], ':') ? '[' . $this->info['host'] . ']' : $this->info['host'];
                if (isset($this->info['port'])) {
                    $host .= ':' . $port;
                }
                if (isset($this->info['user'])) {
                    $headers['Authorization'] ??= 'Basic ' . base64_encode(rawurldecode($this->info['user']) . ':' . rawurldecode($this->info['pass'] ?? ''));
                }
                $headers['Host'] = $host;
                $client->setHeaders($headers);
                $client->setMethod($method);
                if ($body !== null) {
                    $client->setData($body);
                }
                $target = rtrim($this->info['path'] ?? '', '/') . $path;
                if ($query !== []) {
                    $target .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                }
                if (!$client->execute($target)) {
                    throw new Exception('Registry request failed: ' . $client->errMsg, $client->errCode);
                }
                return new ClientProxy($client->body, $client->statusCode, $client->headers, $client->cookies);
            } finally {
                $client->close();
            }
        };
        if (Coroutine::getCid() >= 0) {
            return $operation();
        }
        return $this->requestBlocking($method, $path, $body, $query, $headers);
    }

    protected function checkResponse(ClientProxy $response): bool
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return true;
        }
        throw new Exception('Registry returned HTTP ' . $status . ': ' . substr($response->getBody(), 0, 1024), $status);
    }

    protected function prepareRegistrationOptions(string $name, string $ip, int $port, array $options, float $interval): array
    {
        return $options;
    }

    protected function decodeResponse(ClientProxy $response): array|\stdClass
    {
        $this->checkResponse($response);
        try {
            $decoded = json_decode($response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new Exception('Registry returned invalid JSON', 0, $e);
        }
        if (!is_array($decoded) && !$decoded instanceof \stdClass) {
            throw new Exception('Registry returned an invalid response structure');
        }
        return $decoded;
    }

    /** Managers cannot start async I/O; PHP's HTTP wrapper also handles framing and verified TLS. */
    private function requestBlocking(string $method, string $path, mixed $body, array $query, array $headers): ClientProxy
    {
        if (isset($this->info['user'])) {
            $headers['Authorization'] ??= 'Basic ' . base64_encode(rawurldecode($this->info['user']) . ':' . rawurldecode($this->info['pass'] ?? ''));
        }
        $headers['Connection'] = 'close';
        if (is_array($body)) {
            $body = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
            $headers['Content-Type'] ??= 'application/x-www-form-urlencoded';
        }
        $lines = [];
        foreach ($headers as $key => $value) {
            if (strpbrk($key . $value, "\r\n") !== false) {
                throw new \InvalidArgumentException('Invalid registry HTTP header');
            }
            $lines[] = $key . ': ' . $value;
        }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        foreach (['ssl_cafile'      => 'cafile', 'ssl_capath' => 'capath', 'ssl_cert_file' => 'local_cert',
            'ssl_key_file'          => 'local_pk', 'ssl_passphrase' => 'passphrase', 'ssl_host_name' => 'peer_name',
            'ssl_verify_peer'       => 'verify_peer', 'ssl_verify_host' => 'verify_peer_name',
            'ssl_allow_self_signed' => 'allow_self_signed'] as $option => $key) {
            if (array_key_exists($option, $this->httpOptions)) {
                $ssl[$key] = $this->httpOptions[$option];
            }
        }
        $context = stream_context_create(['http' => [
            'method'        => $method, 'header' => $lines, 'content' => $body ?? '',
            'timeout'       => $this->httpOptions['timeout'] ?? $this->timeout,
            'ignore_errors' => true, 'follow_location' => 0, 'protocol_version' => 1.1,
        ], 'ssl' => $ssl]);
        $url    = $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new Exception('Registry request failed: ' . (error_get_last()['message'] ?? 'connection failed'));
        }
        try {
            $data = @stream_get_contents($stream);
            $meta = stream_get_meta_data($stream);
            if ($data === false || $meta['timed_out']) {
                throw new Exception('Registry response failed or timed out');
            }
            $status          = 0;
            $responseHeaders = [];
            foreach ($meta['wrapper_data'] ?? [] as $line) {
                if (preg_match('/^HTTP\/\S+ (\d{3})/', $line, $match)) {
                    $status          = (int) $match[1];
                    $responseHeaders = [];
                } elseif (($separator = strpos($line, ':')) !== false) {
                    $responseHeaders[strtolower(substr($line, 0, $separator))] = trim(substr($line, $separator + 1));
                }
            }
            return new ClientProxy($data, $status, $responseHeaders, []);
        } finally {
            fclose($stream);
        }
    }

    private function resolveCluster(string $name): ?Cluster
    {
        $pendingKey = $this->generation . ':' . $name;
        $now        = hrtime(true) / 1e9;
        if (isset($this->cache[$name]) && $this->cache[$name]['expires'] > $now) {
            return $this->cache[$name]['cluster'] === null ? null : clone $this->cache[$name]['cluster'];
        }
        if (isset($this->pending[$pendingKey]) && $this->pending[$pendingKey]['owner'] === Coroutine::getCid()) {
            throw new \LogicException('Recursive lookup of the same service is not allowed');
        }
        if (Coroutine::getCid() >= 0 && isset($this->pending[$pendingKey])) {
            $waiter                                  = new Channel(1);
            $this->pending[$pendingKey]['waiters'][] = $waiter;
            $result                                  = $waiter->pop($this->timeout);
            if ($result === false) {
                throw new Exception('Timed out waiting for service discovery');
            }
            if ($result['error'] !== null) {
                throw $result['error'];
            }
            return $result['cluster'] === null ? null : clone $result['cluster'];
        }
        $generation                 = $this->generation;
        $this->pending[$pendingKey] = ['waiters' => [], 'owner' => Coroutine::getCid()];
        $cluster                    = null;
        $error                      = null;
        try {
            $cluster = $this->getCluster($name);
            if ($cluster !== null && $cluster->count() === 0) {
                $cluster = null;
            }
            $ttl = $cluster === null ? $this->negativeTtl : $this->cacheTtl;
            if ($ttl > 0 && $generation === $this->generation) {
                unset($this->cache[$name]);
                while (count($this->cache) >= $this->cacheCapacity) {
                    unset($this->cache[array_key_first($this->cache)]);
                }
                $this->cache[$name] = ['expires' => hrtime(true) / 1e9 + $ttl, 'cluster' => $cluster === null ? null : clone $cluster];
            }
            return $cluster === null ? null : clone $cluster;
        } catch (\Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->notifyWaiters($pendingKey, $cluster, $error);
        }
    }

    private function notifyWaiters(string $key, ?Cluster $cluster, ?\Throwable $error): void
    {
        $waiters = $this->pending[$key]['waiters'];
        unset($this->pending[$key]);
        foreach ($waiters as $waiter) {
            $waiter->push(['cluster' => $cluster, 'error' => $error], 0.001);
        }
    }
}
