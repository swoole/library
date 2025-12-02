<?php
namespace Swoole;

use ArrayAccess;
use stdClass;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\RemoteObject\Client;
use Swoole\RemoteObject\Exception;

class RemoteObject implements ArrayAccess
{
    private int $objectId = 0;
    private int $coroutineId;
    private string $clientId;
    private ?HttpClient $client = null;

    public function __construct($coroutineId, $clientId)
    {
        $this->coroutineId = $coroutineId;
        $this->clientId = $clientId;
    }

    public function getObjectId(): int
    {
        return $this->objectId;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * @throws Exception
     */
    public static function create(Client $client, string $class, array $args): RemoteObject
    {
        $object = new self(Coroutine::getCid(), $client->getId());
        $object->client = Client::getClient($client->getId());
        $rs = $object->execute('/new', [
            'class' => $class,
            'args' => serialize($args),
        ]);
        $object->objectId = intval($rs->object);
        return $object;
    }

    public static function serialize(int $objectId, int $ownerCoroutineId, string $clientId): string
    {
        $object = new self($ownerCoroutineId, $clientId);
        $object->objectId = $objectId;
        return serialize($object);
    }

    /**
     * @throws Exception
     */
    private function execute(string $path, $params = []): stdClass
    {
        $rs = $this->client->post($path, $params);
        if (!$rs) {
            throw new Exception($this->client->errMsg);
        }
        $json = json_decode($this->client->body);
        if ($json->code != 0) {
            $ex = $json->exception;
            throw new Exception('Server Error: ' . $ex->message, $ex->code);
        }
        return $json;
    }

    function __destruct()
    {
        // On the server side, this object will also be constructed,
        // but it is only used for data storage and serialization.
        // No remote calls are executed during destruction.
        if ($this->client) {
            try {
                $this->execute('/destroy', [
                    'object' => $this->objectId,
                ]);
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
        }
    }

    /**
     * @throws Exception
     */
    function __call(string $method, array $args)
    {
        $rs = $this->execute('/call_method', [
            'object' => $this->objectId,
            'method' => $method,
            'args' => serialize($args),
        ]);
        return unserialize($rs->result);
    }

    /**
     * @throws Exception
     */
    function __get(string $property)
    {
        $rs = $this->execute('/read_property', [
            'object' => $this->objectId,
            'property' => $property,
        ]);
        return unserialize($rs->property);
    }

    /**
     * @throws Exception
     */
    function __set(string $property, $value)
    {
        $this->execute('/write_property', [
            'object' => $this->objectId,
            'property' => $property,
            'value' => serialize($value),
        ]);
    }

    /**
     * @throws Exception
     */
    function offsetGet($offset): mixed
    {
        $rs = $this->execute('/offset_get', [
            'object' => $this->objectId,
            'offset' => $offset,
        ]);
        return unserialize($rs->value);
    }

    /**
     * @throws Exception
     */
    function offsetSet($offset, $value): void
    {
        $this->execute('/offset_set', [
            'object' => $this->objectId,
            'offset' => $offset,
            'value' => serialize($value),
        ]);
    }

    /**
     * @throws Exception
     */
    function offsetUnset($offset): void
    {
        $this->execute('/offset_unset', [
            'object' => $this->objectId,
            'offset' => $offset,
        ]);
    }

    /**
     * @throws Exception
     */
    function offsetExists($offset): bool
    {
        $rs = $this->execute('/offset_exists', [
            'object' => $this->objectId,
            'offset' => $offset,
        ]);
        return $rs->exists;
    }

    /**
     * Deserialization can only occur on the client side,
     * and it requires binding an HTTP client to serve as a transmission channel for remote calls.
     */
    function __unserialize(array $data)
    {
        $this->objectId = $data['objectId'];
        $this->coroutineId = $data['coroutineId'];
        $this->clientId = $data['clientId'];
        $this->client = Client::getClient($this->clientId);
    }

    /**
     * Serialization can occur on both the client and the server, and is used solely as a data object.
     */
    function __serialize()
    {
        return [
            'objectId' => $this->objectId,
            'coroutineId' => $this->coroutineId,
            'clientId' => $this->clientId,
        ];
    }
}
