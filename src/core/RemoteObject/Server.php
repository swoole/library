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

use Swoole\Atomic\Long;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server as HttpServer;
use Swoole\RemoteObject;

class Server
{
    public const DEFAULT_PORT = 9567;

    private HttpServer $server;

    private array $objects = [];

    private Long $nextObjectId;

    public function __construct(string $host = '127.0.0.1', int $port = self::DEFAULT_PORT, array $options = [])
    {
        $server = new HttpServer($host, $port, SWOOLE_THREAD);
        if ($options) {
            $server->set($options);
        }
        $server->on('request', [$this, 'onRequest']);
        $this->server       = $server;
        $this->nextObjectId = new Long(1);
    }

    public function start(): bool
    {
        return $this->server->start();
    }

    public function onRequest(Request $request, Response $response): void
    {
        $ctx = new Context($request, $response);
        try {
            $method = $ctx->getHandler();
            if (method_exists($this, $method)) {
                $this->{$method}($ctx);
            } else {
                $ctx->end(['code' => -1, 'msg' => 'invalid request']);
            }
        } catch (\Throwable $e) {
            $ctx->end(['code' => -2, 'exception' => [
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
                'class'   => get_class($e),
            ]]);
        }
    }

    private function addObject($object): int
    {
        // The spl_object_id/spl_object_hash cannot be used,
        // as the IDs they generate will be reused after the objects are destroyed.
        $object_id                 = $this->nextObjectId->add();
        $this->objects[$object_id] = $object;
        return $object_id;
    }

    /**
     * @param mixed $data
     * @throws Exception
     */
    private function marshal(Context $ctx, $data): string
    {
        if (is_object($data) or is_resource($data)) {
            $object_id = $this->addObject($data);
            return RemoteObject::serialize($object_id, $ctx->getCoroutineId(), $ctx->getClientId());
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->marshal($ctx, $value);
            }
        }
        return serialize($data);
    }

    private function unmarshal($data): mixed
    {
        if (is_object($data) and $data instanceof RemoteObject) {
            return $this->objects[$data->getObjectId()];
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->unmarshal($value);
            }
            return $data;
        }
        return $data;
    }

    /**
     * @throws Exception
     */
    private function _new(Context $ctx): void
    {
        $class = '\\' . $ctx->getParam('class');
        $args  = unserialize($ctx->getParam('args'));
        foreach ($args as $key => $value) {
            $args[$key] = $this->unmarshal($value);
        }
        $obj       = new $class(...$args);
        $object_id = $this->addObject($obj);
        $ctx->end(['code' => 0, 'object' => $object_id]);
    }

    /**
     * @throws Exception
     */
    private function _call_method(Context $ctx): void
    {
        $object_id = $ctx->getParam('object');
        if (!isset($this->objects[$object_id])) {
            throw new Exception("object[#{$object_id}] not found");
        }
        $method = $ctx->getParam('method');
        $args   = unserialize($ctx->getParam('args'));
        foreach ($args as $key => $value) {
            $args[$key] = $this->unmarshal($value);
        }
        $obj = $this->objects[$object_id];
        if (!method_exists($obj, $method)) {
            $class = get_class($obj);
            throw new Exception("method[{$class}::{$method}] not found");
        }
        $result = $obj->{$method}(...$args);
        $ctx->end(['code' => 0, 'result' => $this->marshal($ctx, $result)]);
    }

    /**
     * @throws Exception
     */
    private function _read_property(Context $ctx): void
    {
        $object_id = $ctx->getParam('object');
        $property  = $ctx->getParam('property');
        if (!isset($this->objects[$object_id])) {
            throw new Exception("object[#{$object_id}] not found");
        }
        $obj    = $this->objects[$object_id];
        $result = $obj->{$property};
        $ctx->end(['code' => 0, 'property' => $this->marshal($ctx, $result)]);
    }

    /**
     * @throws Exception
     */
    private function _write_property(Context $ctx): void
    {
        $object_id = $ctx->getParam('object');
        $property  = $ctx->getParam('property');
        $value     = unserialize($ctx->getParam('value'));
        if (!isset($this->objects[$object_id])) {
            throw new Exception("object[#{$object_id}] not found");
        }
        $obj              = $this->objects[$object_id];
        $obj->{$property} = $this->unmarshal($value);
        $ctx->end(['code' => 0]);
    }

    /**
     * @throws Exception
     */
    private function _destroy(Context $ctx): void
    {
        $object_id = $ctx->getParam('object');
        if (!isset($this->objects[$object_id])) {
            throw new Exception("object[#{$object_id}] not found");
        }
        unset($this->objects[$object_id]);
        $ctx->end(['code' => 0]);
    }

    private function _offset_get(Context $ctx): void
    {
        $object_id = $ctx->getParam('object');
        $offset    = $ctx->getParam('offset');
        if (!isset($this->objects[$object_id])) {
            throw new Exception("object[#{$object_id}] not found");
        }
        $obj    = $this->objects[$object_id];
        $result = $obj->{$offset};
        $ctx->end(['code' => 0, 'value' => $this->marshal($ctx, $result)]);
    }

    private function _offset_set(Context $ctx)
    {
        $object_id = $ctx->getParam('object');
        $offset    = $ctx->getParam('offset');
        $value     = unserialize($ctx->getParam('value'));
        if (!isset($this->objects[$object_id])) {
            throw new Exception("object[#{$object_id}] not found");
        }
        $obj            = $this->objects[$object_id];
        $obj->{$offset} = $this->unmarshal($value);
        $ctx->end(['code' => 0]);
    }

    private function _offset_unset(Context $ctx)
    {
        $object_id = $ctx->getParam('object');
        $offset    = $ctx->getParam('offset');
        if (!isset($this->objects[$object_id])) {
            throw new Exception("object[#{$object_id}] not found");
        }
        $obj = $this->objects[$object_id];
        unset($obj->{$offset});
        $ctx->end(['code' => 0]);
    }

    private function _offset_exists(Context $ctx)
    {
        $object_id = $ctx->getParam('object');
        $offset    = $ctx->getParam('offset');
        if (!isset($this->objects[$object_id])) {
            throw new Exception("object[#{$object_id}] not found");
        }
        $obj    = $this->objects[$object_id];
        $result = isset($obj->{$offset});
        $ctx->end(['code' => 0, 'value' => $this->marshal($ctx, $result)]);
    }
}
