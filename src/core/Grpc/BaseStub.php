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

/**
 * Base class of generated clients. It turns the protoc-style call descriptors into gRPC requests
 * over the underlying multiplexed Client.
 */
class BaseStub extends VirtualClient
{
    /**
     * Call a remote method that takes a single argument and returns a single output.
     *
     * @param string $method full method path, e.g. /helloworld.Greeter/SayHello
     * @param object $argument the protobuf request message (duck-typed)
     * @param mixed $deserialize array{0: class-string, 1: string|null}|callable
     * @param array $metadata gRPC metadata, merged into the HTTP/2 headers
     * @param array $options may contain a 'headers' map
     * @return array{0: mixed, 1: int, 2: mixed}
     */
    protected function _simpleRequest(
        string $method,
        $argument,
        $deserialize,
        array $metadata = [],
        array $options = [],
    ): array {
        $request          = new Request();
        $request->method  = 'POST';
        $request->path    = $method;
        $request->data    = Parser::serializeMessage($argument);
        $request->headers = array_merge($request->headers, self::buildHeaders($metadata, $options));

        $streamId = $this->send($request);

        return Parser::parseToResultArray($this->recv($streamId), $deserialize);
    }

    /**
     * Call a remote method that takes a stream of arguments and returns a single output.
     *
     * @param mixed $deserialize
     */
    protected function _clientStreamRequest(
        string $method,
        $deserialize,
        array $metadata = [],
        array $options = [],
    ): ClientStreamingCall {
        $call = new ClientStreamingCall();
        $call->setClient($this);
        $call->setMethod($method);
        $call->setDeserialize($deserialize);
        $call->setHeaders(self::buildHeaders($metadata, $options));

        return $call;
    }

    /**
     * Call a remote method that takes a single argument and returns a stream of responses.
     *
     * @param object $argument the single request message, sent immediately
     * @param mixed $deserialize
     */
    protected function _serverStreamRequest(
        string $method,
        $argument,
        $deserialize,
        array $metadata = [],
        array $options = [],
    ): ServerStreamingCall {
        $call = new ServerStreamingCall();
        $call->setClient($this);
        $call->setMethod($method);
        $call->setDeserialize($deserialize);
        $call->setHeaders(self::buildHeaders($metadata, $options));
        // Unlike client/bidi streaming, the server-streaming RPC has exactly one request message:
        // send it while opening the stream, otherwise the call never reaches the server.
        $call->send($argument);

        return $call;
    }

    /**
     * Call a remote method with messages streaming in both directions.
     *
     * @param mixed $deserialize
     */
    protected function _bidiRequest(
        string $method,
        $deserialize,
        array $metadata = [],
        array $options = [],
    ): BidiStreamingCall {
        $call = new BidiStreamingCall();
        $call->setClient($this);
        $call->setMethod($method);
        $call->setDeserialize($deserialize);
        $call->setHeaders(self::buildHeaders($metadata, $options));

        return $call;
    }

    /**
     * Merge call metadata and $options['headers'] into lowercase HTTP/2 headers.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private static function buildHeaders(array $metadata, array $options): array
    {
        $headers = [];
        $extra   = $options['headers'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $key => $value) {
                $headers[strtolower((string) $key)] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }
        foreach ($metadata as $key => $value) {
            $headers[strtolower((string) $key)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $headers;
    }
}
