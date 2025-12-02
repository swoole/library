<?php

namespace Swoole\RemoteObject;


use Swoole\Coroutine;
use Swoole\Exception;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\RemoteObject;

class Client
{
    private static array $clients = [];
    private HttpClient $client;
    private string $id;
    private int $ownerCoroutineId;
    public function __construct(string $host = '127.0.0.1', int $port = Server::DEFAULT_PORT)
    {
        $this->id = base64_encode(random_bytes(16));
        $this->client = new HttpClient($host, $port);
        $this->ownerCoroutineId = Coroutine::getCid();
        $this->client->setHeaders([
            'client-id' => $this->id,
            'coroutine-id' => $this->ownerCoroutineId,
        ]);
        self::$clients[$this->id] = $this;
    }

    /**
     * @throws Exception
     */
    public function create(string $class, mixed ...$args): RemoteObject
    {
        return RemoteObject::create($this, $class, $args);
    }

    /**
     * @throws Exception
     */
    static function getClient(string $clientId): HttpClient
    {
        if (empty($clientId)) {
            throw new Exception('RemoteObject is not bound to a client');
        }
        if (!isset(self::$clients[$clientId])) {
            throw new Exception('Client[#' . $clientId . '] not found');
        }
        return self::$clients[$clientId]->client;
    }

    public function getId(): string
    {
        return $this->id;
    }
}