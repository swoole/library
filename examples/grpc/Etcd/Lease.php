<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc\Example\Etcd;

use Etcdserverpb\LeaseClient;
use Etcdserverpb\LeaseGrantRequest;
use Etcdserverpb\LeaseKeepAliveRequest;
use Etcdserverpb\LeaseRevokeRequest;
use Swoole\Coroutine;
use Swoole\Grpc\Result;

/**
 * Lease helpers: grant, revoke, and a background keepalive loop (clientv3 Lease.KeepAlive style).
 * The keepalive runs in a coroutine and periodically refreshes the lease until stop() is called;
 * the recommended TTL is roughly 3x the refresh interval.
 */
final class Lease
{
    private LeaseClient $client;

    private int $refreshSeconds;

    private bool $stopped = false;

    public function __construct(LeaseClient $client, int $refreshSeconds = 3)
    {
        $this->client         = $client;
        $this->refreshSeconds = max(1, $refreshSeconds);
    }

    public function grant(int $ttl): Result
    {
        $request = new LeaseGrantRequest();
        $request->setTTL($ttl);
        return Result::fromTriple($this->client->LeaseGrant($request));
    }

    public function revoke(int $leaseId): Result
    {
        $request = new LeaseRevokeRequest();
        $request->setID($leaseId);
        return Result::fromTriple($this->client->LeaseRevoke($request));
    }

    /**
     * Keep a lease alive in a background coroutine. Returns immediately; call stop() to end.
     *
     * @param callable(int, bool): void|null $onTick receives (leaseId, ok) after each refresh
     */
    public function keepAlive(int $leaseId, ?callable $onTick = null): void
    {
        $this->stopped = false;
        Coroutine::create(function () use ($leaseId, $onTick): void {
            while (!$this->stopped) {
                $call    = $this->client->LeaseKeepAlive();
                $request = new LeaseKeepAliveRequest();
                $request->setID($leaseId);
                $sent = $call->push($request);
                $ok   = false;
                if ($sent) {
                    [$reply, $status] = $call->recv($this->refreshSeconds * 2);
                    $ok               = $status === 0 && $reply !== null;
                }
                $call->cancel();
                if ($onTick !== null) {
                    $onTick($leaseId, $ok);
                }
                if ($this->stopped) {
                    return;
                }
                Coroutine::sleep($this->refreshSeconds);
            }
        });
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
