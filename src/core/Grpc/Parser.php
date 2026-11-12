<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc;

use Swoole\Http2\Response;

/**
 * gRPC wire-format helpers. Message (de)serialization is duck-typed: any object exposing
 * encode(), serializeToString() or serialize(), and mergeFromString()/decode() on the way back,
 * works. That keeps the runtime independent of google/protobuf.
 */
class Parser
{
    /** Status returned when the server sent no response at all. */
    public const ERROR_NO_RESPONSE = -1;

    /** 1 byte compression flag + 4 bytes big-endian payload length. */
    private const HEADER_LENGTH = 5;

    /**
     * Wrap a serialized message in a gRPC length-prefixed frame.
     */
    public static function pack(string $data): string
    {
        return pack('CN', 0, strlen($data)) . $data;
    }

    /**
     * Strip the gRPC frame header, validating its length prefix first.
     */
    public static function unpack(string $data): string
    {
        $length = strlen($data);
        if ($length < self::HEADER_LENGTH) {
            throw new \InvalidArgumentException('Invalid gRPC frame: shorter than the 5-byte header');
        }
        $header = unpack('Ccompression/Nlength', substr($data, 0, self::HEADER_LENGTH));
        if ($header === false || $header['length'] !== $length - self::HEADER_LENGTH) {
            throw new \InvalidArgumentException('Invalid gRPC frame: length prefix does not match the payload');
        }

        return substr($data, self::HEADER_LENGTH);
    }

    /**
     * Serialize a message and wrap it in a gRPC frame.
     * @param mixed $data
     */
    public static function serializeMessage($data): string
    {
        if (!is_object($data)) {
            throw new \InvalidArgumentException('Message must be an object');
        }

        if (method_exists($data, 'encode')) {
            $serialized = $data->encode();
        } elseif (method_exists($data, 'serializeToString')) {
            $serialized = $data->serializeToString();
        } elseif (method_exists($data, 'serialize')) {
            $serialized = $data->serialize();
        } else {
            throw new \InvalidArgumentException('Message must provide encode(), serializeToString() or serialize()');
        }

        if (!is_string($serialized)) {
            throw new \InvalidArgumentException('Message serialization must return a string');
        }

        return self::pack($serialized);
    }

    /**
     * Unpack a gRPC frame and build the reply object.
     *
     * @param mixed $deserialize array{0: class-string, 1: string|null}|callable
     * @return object|mixed
     */
    public static function deserializeMessage($deserialize, ?string $value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = self::unpack($value);

        if (is_array($deserialize)) {
            [$className, $deserializeFunc] = $deserialize + [null, null];
            if (!is_string($className) || !class_exists($className)) {
                throw new \InvalidArgumentException('Invalid gRPC deserialize class');
            }
            $obj = new $className();
            if (is_string($deserializeFunc) && $deserializeFunc !== '' && method_exists($obj, $deserializeFunc)) {
                $obj->{$deserializeFunc}($value);
            } elseif (method_exists($obj, 'mergeFromString')) {
                $obj->mergeFromString($value);
            } else {
                throw new \InvalidArgumentException('Message must provide mergeFromString() or a decode method');
            }

            return $obj;
        }

        if (!is_callable($deserialize)) {
            throw new \InvalidArgumentException('Invalid gRPC deserialize callable');
        }

        return $deserialize($value);
    }

    /**
     * Turn an HTTP/2 response into the [reply, status, response] triple returned by generated clients.
     *
     * @param Response|false|null $response
     * @param mixed $deserialize
     * @return array{0: mixed, 1: int, 2: mixed}
     */
    public static function parseToResultArray($response, $deserialize): array
    {
        if (!$response) {
            return ['No response', self::ERROR_NO_RESPONSE, $response];
        }

        $headers = $response->headers ?? [];
        if ($response->statusCode !== 0 && intdiv($response->statusCode, 100) !== 2) {
            return ['Http status Error', $response->errCode ?: $response->statusCode, $response];
        }

        $grpcStatus = (int) ($headers['grpc-status'] ?? 0);
        if ($grpcStatus !== 0) {
            return [$headers['grpc-message'] ?? 'Unknown error', $grpcStatus, $response];
        }

        $reply = self::deserializeMessage($deserialize, $response->data);

        return [$reply, $grpcStatus, $response];
    }
}
