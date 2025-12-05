<?php

namespace Swoole\MongoDB;

use Swoole\RemoteObject\ProxyTrait;
use Swoole\RemoteObject;

class Client
{
    public const DEFAULT_URI = 'mongodb://127.0.0.1/';

    protected RemoteObject $client;

    use ProxyTrait;

    public function __construct(?string $uri = self::DEFAULT_URI, array $uriOptions = [], array $driverOptions = [])
    {
        $remoteObjectClient = swoole_library_get_option('mongodb_remote_object_client');
        if ($remoteObjectClient === null) {
            $remoteObjectClient = swoole_get_default_remote_object_client();
        }
        $this->client = $remoteObjectClient->create(\MongoDB\Client::class, $uri, $uriOptions, $driverOptions);
    }

    protected function getObject(): RemoteObject
    {
        return $this->client;
    }
}
