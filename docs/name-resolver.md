# Service discovery with NameResolver

NameResolver connects Swoole's coroutine HTTP client to Redis, Consul and the
Nacos 1.x/2.x naming OpenAPI. A resolver maps a logical service name to IP/port
endpoints; HTTP continues to send the logical Host header. Registration and
health reporting belong to the process responsible for the listening instance.

## Client configuration

```php
$resolver = (new Swoole\NameResolver\Consul('https://consul.example:8501'))
    ->withToken(getenv('CONSUL_HTTP_TOKEN'))
    ->withFilter(static fn(string $host): bool => str_ends_with($host, '.service'))
    ->withTimeout(1.0)
    ->withCache(1.0, 1024, 0.1);

swoole_name_resolver_add($resolver);
Swoole\Coroutine\run(function () {
    // Omitting the port uses the endpoint port supplied by service discovery.
    $client = new Swoole\Coroutine\Http\Client('orders.service');
    $client->set(['timeout' => 2.0, 'max_retries' => 2]);
    if (!$client->get('/orders')) {
        throw new RuntimeException($client->errMsg, $client->errCode);
    }
    echo $client->body;
    $client->close();
});
swoole_name_resolver_remove($resolver);
```

An explicit nonzero HTTP client port takes precedence over a discovered port.
Keepalive connections remain attached to their selected node until closed.
Redis and Consul support IPv4/IPv6 endpoints. `Context(AF_INET6, true)` requests
IPv6 resolution; the HTTP client's socket family still determines which address
family it can use. Registry URLs accept bracketed IPv6 literals.

`max_retries` bounds client retry attempts. Within one request each selected
cluster node is consumed at most once; exhaustion fails without querying the
registry repeatedly or falling through to public DNS. A new request gets a fresh
snapshot. The existing HTTP client also retries HTTP 502/503 with this setting:
use it only where retrying the request is appropriate. Response/read failures
are not automatically retried by NameResolver.

## Resolver contract and isolation

- A filter returning anything other than `true` skips this resolver. Without a
  filter, absent services and registry transport/protocol failures fall back to
  DNS. With an explicit service-domain filter, missing services fail definitively
  and registry failures propagate; unrelated domains always continue through DNS.
- `lookup()` returns a node array for one endpoint, a `Cluster` for several,
  `''` for a definitive missing service, or `null` when skipped.
- Direct backend operations and filtered lookup failures throw
  `Swoole\NameResolver\Exception`. Failures are never cached as missing services.
- `Cluster::add($ip, $port, $weight)` accepts ports 1–65535 and finite weights
  0–65535, including fractional weights. Zero disables the endpoint. Selection
  is weighted random without replacement. Equivalent IP/port entries are
  deduplicated; adding again updates their weight.
- Cached clusters and clusters returned by custom resolvers are cloned before
  consumption. Concurrent requests do not consume each other's endpoints.
- A native `Context` belongs to one lookup sequence. It cannot be used by two
  coroutines concurrently. Changing the service or address family clears its
  previous result. Create a new Context for a new independent request.
- Resolver objects and their registration list are local to each PHP thread.
  Configure client resolvers in each worker thread. Adding the same resolver
  twice is idempotent. Registry changes during a suspended lookup take effect on subsequent lookups; an active callback retains
  its resolver safely until it completes.

## Caching and transport

`withCache($ttl, $capacity = 1024, $negativeTtl = 0.1)` uses seconds and a
monotonic clock. Defaults are 1 second for positive results and 0.1 second for
missing services. The cache has a fixed capacity and FIFO eviction. To disable
both caches use `withCache(0, 1024, 0)`. Direct `getCluster()` calls always query
the backend. Concurrent misses for the same service share one in-flight query;
each waiter receives its own cluster and has a bounded wait.

Successful registration, deregistration and scope changes invalidate local
cache entries. `clearCache($name)` invalidates one service; `clearCache()` clears
all. An operation started before invalidation cannot repopulate the new cache.
Changes made by other processes become visible after the cache TTL. Choose TTLs
shorter than your acceptable endpoint-removal delay. There is no stale-on-error
fallback or automatic passive circuit breaker.

`withTimeout($seconds)` sets finite positive DNS/connect/read timeouts and the
maximum wait for an in-flight query. It is a timeout per transport phase, not an
overall HTTP business-request deadline. HTTP registries use a dedicated native
coroutine client, independent of the process-wide `http_client_driver` setting;
calls made outside a coroutine use synchronous PHP streams with read/connect
timeouts, including final deregistration in BASE managers where async I/O is
forbidden. System DNS resolution outside coroutines follows PHP/OS resolver
limits rather than a coroutine DNS deadline. Registry DNS uses the system DNS
resolver directly, preventing service discovery recursion and permanent pinning to an address resolved at construction.

The original registry hostname is preserved in Host and TLS SNI. Peer and
hostname verification are enabled for HTTPS. Configure private CAs or mutual
TLS with `withHttpOptions(['ssl_cafile' => ..., 'ssl_cert_file' => ...,
'ssl_key_file' => ...])`. URL credentials provide HTTP Basic authentication.

## Redis

```php
$resolver = (new Swoole\NameResolver\Redis('redis://user:password@127.0.0.1:6379/2'))
    ->withTimeout(1.0)
    ->withTtl(30);
$resolver->join('orders.service', '192.0.2.10', 9501, ['weight' => 100]);
// Renew well before expiry, using the same options as registration.
$resolver->heartbeat('orders.service', '192.0.2.10', 9501, ['weight' => 100]);
$resolver->leave('orders.service', '192.0.2.10', 9501);
```

Percent-encode URL credentials. `rediss://` uses TLS; `withTlsOptions()` accepts
PHP stream TLS options such as `cafile`, `local_cert` and `local_pk`. Connections
have bounded connect/read timeouts and are closed after each operation. The
adapter targets standalone Redis and uses single-key atomic Lua operations;
it does not create shared connections across coroutines or implement Redis Cluster
topology routing.

`withTtl(0)` is the compatibility default: persistent registrations require
explicit deregistration. Set a positive TTL for crash recovery, and renew before
expiry. `join()` options can override `ttl`. Expiry uses Redis server time and is
checked before returning endpoints; expired records are removed lazily on lookup.
Thus inactive keys may retain expired entries until queried. Existing `ip:port`
set members remain readable. New members are JSON records containing host, port,
weight and expiry; re-register old entries to adopt weights and leases. No
companion metadata keys can collide with another service's name.

## Consul

Discovery uses `/v1/health/service/:service?passing=true`, so critical checks and
maintenance instances are excluded. Empty service addresses fall back to the
node address. `withToken()`, `withDatacenter()` and `withNamespace()` configure
ACLs and scope. Namespace support depends on the Consul deployment.

Registration options include `weight`, `tags`, `meta`, `check` and `checks`.
Supply a TCP/HTTP health check or a TTL check with a deregistration policy:

```php
$resolver->join('orders.service', '192.0.2.10', 9501, [
    'check' => ['TTL' => '30s', 'Status' => 'passing',
                'DeregisterCriticalServiceAfter' => '1m'],
]);
$resolver->heartbeat('orders.service', '192.0.2.10', 9501);
$resolver->enableMaintenanceMode('orders.service', '192.0.2.10', 9501);
$resolver->enableMaintenanceMode('orders.service', '192.0.2.10', 9501, false);
```

`heartbeat()` renews the default TTL check; use `['checkId' => ...]` for an explicit
check ID. `updateHealth($checkId, $status, $output)` supports passing, warning and
critical states. Consul weights must be integers.

## Nacos

The adapter implements the naming v1 OpenAPI used by Nacos 1.x/2.x, tested with
2.5.4. It does not implement the Nacos 3.x API or its SDK/gRPC registration model.
Use `withNamespace()`, `withGroup()` and `withCluster()` before registration;
these settings apply to registration, discovery, heartbeat and deregistration.
The public namespace maps to the empty namespace ID. Registration-only scope
overrides are rejected to prevent instances that cannot later be found/removed.

`withAccessToken()` configures an existing token. Token acquisition/renewal is
owned by the application. `join()` options include fractional `weight`, `enabled`
and `metadata`. Discovery requests healthy nodes and also rejects unhealthy,
disabled and zero-weight instances locally.

Ephemeral registration is the default. Call `heartbeat()` at the returned
interval in seconds, passing the same weight/metadata as `join()`. Persistent
instances configured with `withEphemeral(false)` use server-side health checks
instead of client heartbeats. Stop and await heartbeat tasks before `leave()`.

## Automatic server lifecycle

Both `Swoole\Http\Server` (including WebSocket servers) and
`Swoole\Coroutine\Http\Server` recognize these settings:

```php
$resolver = (new Swoole\NameResolver\Redis('redis://127.0.0.1:6379'))->withTimeout(1);
$server = new Swoole\Http\Server('0.0.0.0', 9501);
$server->set([
    'service_name' => 'orders.service',
    'service_address' => '192.0.2.10',
    'name_resolver' => [$resolver],
]);
$server->on('request', static fn ($request, $response) => $response->end('OK'));
$server->start();
```

No application `join()`, timer or `leave()` calls are needed. `service_name`
selects the single matching configured resolver; set `service_resolver` explicitly
when several match. `service_address` defaults to the listening IP; wildcard
addresses require an explicit reachable advertised IP. `service_port` defaults
to the listening port. `service_options` holds backend registration options;
`service_heartbeat_interval` defaults to 5 seconds. Resolver settings are cloned
for the registration so later client-side scope changes cannot orphan it.

`Swoole\Server\Helper::onStart()` owns automatic registration in the master
reactor for PROCESS/THREAD servers. No user `onStart` callback or additional
process is required. THREAD mode does not recover individual crashed threads;
a fatal thread crash terminates the process and registry leases expire. Initial
registry outages are logged and retried at the heartbeat interval. `onBeforeShutdown()` stops renewal, waits for an in-flight
heartbeat and then deregisters while the event loop drains. Forced shutdown or
registry unavailability may prevent deregistration; lease expiry remains required.
BASE worker 0 renews the shared endpoint; replacement workers restore renewal,
and a worker reload cancels old renewals without deregistering its successor.
`onWorkerStart(workerId=0)` checks registration liveness and restores it after a
crash even though PHP `onStart` is not invoked again. BASE final deregistration
is performed once by the manager at `onShutdown`, after workers have stopped.
This integration requires the bundled library enabled. Configure discovery on
the primary listener; additional ports are not registered automatically.

Coroutine HTTP servers register when `start()` begins and deregister when its
accept loop ends, including `shutdown()` and listener failure. Initial registration
failure propagates to the caller. Subsequent heartbeat errors are logged and
retried. The same source-loaded library is usable with `swoole.enable_library=Off`.
See `examples/service/discovery_server.php`.

Renewals run serially. Missing registrations are recreated, and deregistration
cannot race with a late heartbeat. Managed Redis registrations always have a
positive lease. Managed Consul registrations add a TTL check alongside supplied
health checks; an expired check excludes the instance and Consul later removes
it. TTL defaults to the larger of three heartbeat intervals or the interval plus
twice the registry timeout; explicit TTL must exceed interval plus timeout.
Managed Nacos registrations require ephemeral instances and respect a shorter
server-supplied heartbeat interval. Abrupt process death relies on these expiry
mechanisms; successful deregistration cannot be guaranteed during an outage.

`join()` and `leave()` remain manual operations. Custom integration code can use
`$registration = $resolver->register($name, $ip, $port, $options, $interval)` inside
a coroutine, inspect `getLastError()` / `getLastHeartbeat()`, attach `onError()`,
and call `stop()` in `finally`. The renewal loop keeps the event loop alive until
stopped; there is no destructor network I/O. Ownership cannot be cloned or
serialized, and a forked child cannot renew or remove its parent's registration.

## Tests

The unit suite covers weighted/disabled nodes, cache capacity/expiry, concurrent
misses/errors/invalidation, malformed responses, transport timeouts, Redis ACLs
and legacy records, Consul maintenance/TTL health, and Nacos heartbeat/scoping.
The existing Docker Compose services provide the integration fixtures:

```sh
php -d swoole.enable_library=Off vendor/bin/counit --testsuite concurrent --filter NameResolver
php -d swoole.enable_library=Off vendor/bin/phpunit --testsuite serial --filter NameResolver
```

Extension PHPTs cover single-node arrays, IPv6 ports, Context reuse, finite
exhaustion, callback exceptions, mutation during suspended lookups and actual
HTTP service discovery/failover. Both source-loaded and embedded library modes
must pass before release.
