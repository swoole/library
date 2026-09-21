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
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\RemoteObject;

class Client
{
    /**
     * The live clients, keyed by client id.
     *
     * The references are weak on purpose. A RemoteObject holds a strong reference to the client it came
     * from, so a client stays reachable here for exactly as long as one of its remote objects is alive,
     * which is the lifetime RemoteObject::__destruct() needs to be able to send /destroy. A client nobody
     * else holds on to is freed right away instead of being pinned for the lifetime of the process.
     *
     * @var array<string, \WeakReference>
     */
    private static array $clients = [];

    private readonly HttpClient $client;

    // Not readonly, so that it has a default: __destruct() also runs on an instance built without the constructor.
    private string $id = '';

    private readonly int $ownerCoroutineId;

    public function __construct(string $host = '127.0.0.1', int $port = Server::DEFAULT_PORT, array $options = [])
    {
        $this->id               = $this->genUuid();
        $this->client           = new HttpClient($host, $port);
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
        $rs = $this->client->post($path, $array);
        if (!$rs) {
            throw new Exception($this->client->errMsg);
        }
        $result = unserialize($this->client->body);
        if (!is_array($result) || !array_key_exists('code', $result)) {
            throw new Exception('Malformed response from the remote object server');
        }
        if ($result['code'] != 0) {
            $ex = $result['exception'];
            throw new Exception('Server Error: ' . $ex['message'], $ex['code']);
        }
        return $result;
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

    private function genUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
