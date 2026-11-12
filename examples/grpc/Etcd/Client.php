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

use Etcdserverpb\DeleteRangeRequest;
use Etcdserverpb\KVClient;
use Etcdserverpb\LeaseClient;
use Etcdserverpb\PutRequest;
use Etcdserverpb\RangeRequest;
use Etcdserverpb\WatchClient;
use Swoole\Grpc\Client as GrpcClient;
use Swoole\Grpc\Result;
use Swoole\Grpc\Status\Status;

/**
 * A small etcd v3 facade (jetcd/clientv3 style) over the generated stubs. All stubs share one
 * multiplexed Swoole\Grpc\Client connection via the ['use' => $client] option.
 *
 * This is an example-level convenience layer: it lives under examples/ and therefore requires
 * the generated stubs and google/protobuf (dev dependencies). The Swoole\Grpc runtime itself
 * stays protobuf-free. Every method returns a Result so callers can inspect the gRPC Status.
 */
final class Client
{
    private GrpcClient $connection;

    private KVClient $kv;

    private WatchClient $watch;

    private LeaseClient $lease;

    public function __construct(string $uri, array $opts = [])
    {
        $this->connection = new GrpcClient($uri, $opts);
        $shared           = ['use' => $this->connection];
        $this->kv         = new KVClient($uri, $shared);
        $this->watch      = new WatchClient($uri, $shared);
        $this->lease      = new LeaseClient($uri, $shared);
    }

    public function put(string $key, string $value, int $leaseId = 0): Result
    {
        $request = new PutRequest();
        $request->setKey($key);
        $request->setValue($value);
        if ($leaseId > 0) {
            $request->setLease($leaseId);
        }
        return Result::fromTriple($this->kv->Put($request));
    }

    public function get(string $key): Result
    {
        $request = new RangeRequest();
        $request->setKey($key);
        return Result::fromTriple($this->kv->Range($request));
    }

    /**
     * Get all keys with the given prefix (etcd's WithPrefix).
     */
    public function getPrefix(string $prefix): Result
    {
        $request = new RangeRequest();
        $request->setKey($prefix);
        $request->setRangeEnd(self::prefixEnd($prefix));
        return Result::fromTriple($this->kv->Range($request));
    }

    public function delete(string $key): Result
    {
        $request = new DeleteRangeRequest();
        $request->setKey($key);
        return Result::fromTriple($this->kv->DeleteRange($request));
    }

    public function txn(): Txn
    {
        return new Txn($this->kv);
    }

    public function watcher(): Watcher
    {
        return new Watcher($this->watch);
    }

    public function lease(): Lease
    {
        return new Lease($this->lease);
    }

    public function kv(): KVClient
    {
        return $this->kv;
    }

    public function connection(): GrpcClient
    {
        return $this->connection;
    }

    public function close(): void
    {
        $this->connection->close();
    }

    /**
     * Compute the exclusive end key for a prefix range (clientv3 GetPrefixRangeEnd).
     */
    public static function prefixEnd(string $prefix): string
    {
        $bytes = array_values(unpack('C*', $prefix) ?: []);
        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            if ($bytes[$i] < 0xFF) {
                $bytes[$i]++;
                return pack('C*', ...array_slice($bytes, 0, $i + 1));
            }
        }

        return "\0";
    }

    public static function ok(Result $result): Status
    {
        return $result->getStatus();
    }
}
