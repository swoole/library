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

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\RemoteObject;

class Context
{
    public string $clientId;

    public int $coroutineId;

    public Request $request;

    public function __construct(Request $request, public Response $response)
    {
        $this->clientId    = $request->header['client-id'] ?? '';
        $this->coroutineId = intval($request->header['coroutine-id'] ?? 0);
        $this->request     = $request;
    }

    public function end(array $data): void
    {
        $this->response->header('Content-Type', 'application/octet-stream');
        $this->response->end(serialize($data));
    }

    public function getHandler(): string
    {
        $path = $this->request->server['request_uri'];
        return str_replace('/', '_', $path);
    }

    public function getParam(string $name): string
    {
        if (!isset($this->request->post[$name])) {
            throw new Exception("param[{$name}] is empty");
        }
        return $this->request->post[$name];
    }

    public function getDataParam(string $name): mixed
    {
        return self::decode($this->getParam($name));
    }

    /** Decode the wire format, allowing only protocol references; ordinary objects stay on the server. */
    public static function decode(string $value): mixed
    {
        $data = @unserialize($value, ['allowed_classes' => [RemoteObject::class], 'max_depth' => 256]);
        if ($data === false && $value !== 'b:0;') {
            throw new Exception('Malformed serialized remote object data');
        }
        self::checkData($data);
        return $data;
    }

    public function getCoroutineId(): int
    {
        $coroutine_id = $this->request->header['coroutine-id'] ?? '';
        if (!$coroutine_id) {
            throw new Exception('coroutine-id is empty');
        }
        return intval($coroutine_id);
    }

    public function getClientId(): string
    {
        $client_id = $this->request->header['client-id'] ?? '';
        if (!$client_id) {
            throw new Exception('client-id is empty');
        }
        return $client_id;
    }

    private static function checkData(mixed $item, int $depth = 0): void
    {
        if ($depth > 256) {
            throw new Exception('Remote object data exceeds the maximum nesting depth');
        }
        if (is_object($item) && !$item instanceof RemoteObject) {
            throw new Exception('Serialized class is not allowed');
        }
        if (is_array($item)) {
            foreach ($item as $value) {
                self::checkData($value, $depth + 1);
            }
        }
    }
}
