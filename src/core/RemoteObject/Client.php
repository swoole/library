<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\RemoteObject;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\RemoteObject;
use Swoole\Timer;

class Client
{
    private const RELEASE_BATCH_SIZE = 16;

    private const RELEASE_DELAY_MS = 100;

    /** @var array<int, int> */
    private array $releaseQueue = [];

    private ?int $releaseTimer = null;

    /**
     * The live clients, keyed by client id.
     *
     * The references are weak on purpose. A RemoteObject holds a strong reference to the client it came
     * from, so a client stays reachable here for exactly as long as one of its remote objects is alive,
     * which is the lifetime RemoteObject::__destruct() needs to queue its release. A timer callback keeps
     * the client alive until pending releases have been sent.
     *
     * @var array<string, \WeakReference>
     */
    private static array $clients = [];

    private readonly HttpClient $client;

    /**
     * Serializes the remote calls made through this client.
     *
     * The HTTP connection underneath can only be driven by one coroutine at a time: a second coroutine using
     * it while a request is in flight is a fatal "Socket has already been bound to another coroutine". A client
     * is routinely shared, though: by a service object handling concurrent requests, and by every remote object
     * it created, whose destructor queues a release from whichever coroutine drops the last reference.
     */
    private readonly Channel $lock;

    /**
     * The coroutine currently holding the lock, if any.
     */
    private int $lockOwner = -1;

    // Not readonly, so that it has a default: __destruct() also runs on an instance built without the constructor.
    private string $id = '';

    private readonly int $ownerCoroutineId;

    public function __construct(string $host = '127.0.0.1', int $port = Server::DEFAULT_PORT, array $options = [])
    {
        $this->id               = $this->genUuid();
        $this->client           = new HttpClient($host, $port);
        $this->lock             = new Channel(1);
        $this->ownerCoroutineId = Coroutine::getCid();

        $headers = [
            'client-id'    => $this->id,
            'coroutine-id' => $this->ownerCoroutineId,
        ];
        if (isset($options['api_key'])) {
            $headers['x-api-key'] = $options['api_key'];
        }
        $this->client->setHeaders($headers);

        // RemoteObject::__unserialize() looks the client up here by id to re-bind remote objects the server returns.
        self::$clients[$this->id] = \WeakReference::create($this);
    }

    public function __destruct()
    {
        // self::$clients only holds a weak reference, so its entry would outlive the client. Drop it here, but only
        // if it is this instance's own: one built without the constructor has no entry.
        if ((self::$clients[$this->id] ?? null)?->get() === $this) {
            unset(self::$clients[$this->id]);
        }
    }

    /**
     * A clone would share the id, the registry entry and the HTTP connection of the client it was made from.
     */
    private function __clone()
    {
    }

    public function create(string $class, mixed ...$args): RemoteObject
    {
        return RemoteObject::create($this, $class, $args);
    }

    public function call(string $fn, mixed ...$args): mixed
    {
        return RemoteObject::call($this, $fn, $args);
    }

    /**
     * @throws Exception
     */
    public static function getInstance(string $clientId): ?static
    {
        if (empty($clientId)) {
            throw new Exception('RemoteObject is not bound to a client');
        }
        $client = (self::$clients[$clientId] ?? null)?->get();
        if ($client === null) {
            // Drop a dead reference if one is ever found.
            unset(self::$clients[$clientId]);
            return null;
        }
        return $client instanceof static ? $client : null;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function execute(string $path, array $array)
    {
        return $this->withLock(fn () => $this->request($path, $array));
    }

    public function release(int $objectId): void
    {
        $this->releaseQueue[$objectId] = $objectId;
        // A destructor may run inside request unserialization. It must not wait for its own lock.
        if (count($this->releaseQueue) >= self::RELEASE_BATCH_SIZE
            && $this->lockOwner !== Coroutine::getCid()) {
            $this->flushReleasedObjects();
        }
        if ($this->releaseQueue === [] || $this->releaseTimer !== null) {
            return;
        }
        $this->releaseTimer = Timer::after(self::RELEASE_DELAY_MS, function (): void {
            $this->releaseTimer = null;
            if ($this->releaseQueue === []) {
                return;
            }
            // Timer callbacks can run without a coroutine when enable_coroutine is disabled.
            if (Coroutine::getCid() === -1) {
                Coroutine::create($this->flushReleasedObjects(...));
            } else {
                $this->flushReleasedObjects();
            }
        });
    }

    public function ping(): bool
    {
        try {
            $this->execute('/ping', []);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function withLock(callable $callback): mixed
    {
        $cid = Coroutine::getCid();
        // Outside a coroutine both sides are -1. The call goes on to the lock below, where Channel::push() ends it
        // with the fatal error "API must be called in the coroutine".
        if ($cid !== -1 && $this->lockOwner === $cid) {
            // Only reachable from a destructor the garbage collector runs in the middle of a call of this same
            // coroutine. Waiting would be waiting for itself.
            throw new Exception('The remote object client is already in use by the current coroutine');
        }
        if (!$this->lock->push(true)) {
            // The wait was cancelled (Coroutine::cancel()) or the channel closed; the lock was never acquired, so
            // this coroutine must not run its call alongside the holder, nor release the holder's lock in finally.
            throw new Exception('Cancelled while waiting for the remote object client', $this->lock->errCode);
        }
        $this->lockOwner = $cid;
        try {
            return $callback();
        } finally {
            $this->lockOwner = -1;
            $this->lock->pop();
        }
    }

    private function flushReleasedObjects(): void
    {
        try {
            $this->withLock(fn () => $this->flushReleaseQueue());
        } catch (Exception $e) {
            error_log($e->getMessage());
        }
    }

    // Called with the HTTP connection locked. Detach the queue before the request can yield.
    private function flushReleaseQueue(): void
    {
        if ($this->releaseTimer !== null) {
            Timer::clear($this->releaseTimer);
            $this->releaseTimer = null;
        }
        $objects            = $this->releaseQueue;
        $this->releaseQueue = [];
        foreach (array_chunk($objects, self::RELEASE_BATCH_SIZE, true) as $batch) {
            try {
                $this->request('/destroy_batch', ['objects' => serialize(array_values($batch))]);
            } catch (Exception $e) {
                // Retry on subsequent releases, without scheduling an endless loop on a broken connection.
                $this->releaseQueue = $objects + $this->releaseQueue;
                error_log($e->getMessage());
                return;
            }
            foreach ($batch as $objectId) {
                unset($objects[$objectId]);
            }
        }
    }

    private function request(string $path, array $array): array
    {
        if (!$this->client->post($path, $array)) {
            throw new Exception($this->client->errMsg);
        }
        // A malformed body is reported below as an exception, without an unserialize warning.
        $result = @unserialize($this->client->body);
        if (!is_array($result) || !array_key_exists('code', $result)) {
            throw new Exception('Malformed response from the remote object server');
        }
        if ($result['code'] != 0) {
            throw Exception::fromResponse($result);
        }
        return $result;
    }

    private function genUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
