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

use Swoole\Grpc\Compression\GzipCompressor;
use Swoole\Grpc\Interceptor\CallContext;
use Swoole\Grpc\Interceptor\Chain;
use Swoole\Grpc\Interceptor\Interceptor;
use Swoole\Grpc\Internal\Frame;
use Swoole\Http2\Response;

/**
 * Base class of generated clients. It turns the protoc-style call descriptors into gRPC requests
 * over the underlying multiplexed Client.
 */
class BaseStub extends VirtualClient
{
    /** @var array<int, Interceptor> */
    private array $interceptors = [];

    /**
     * Register an interceptor (outermost last). Returns $this for fluent chaining.
     */
    public function withInterceptor(Interceptor $interceptor): static
    {
        $this->interceptors[] = $interceptor;
        return $this;
    }

    /**
     * Replace the interceptor chain.
     *
     * @param array<int, Interceptor> $interceptors
     */
    public function setInterceptors(array $interceptors): void
    {
        $this->interceptors = array_values($interceptors);
    }

    /**
     * Idiomatic unary RPC entry point returning a Result (reply + Status + trailers).
     * Unlike _simpleRequest(), callers can inspect the status or turn it into an exception.
     *
     * @param object $argument the protobuf request message (duck-typed)
     * @param mixed $deserialize array{0: class-string, 1: string|null}|callable
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $options
     */
    public function invokeUnary(
        string $method,
        $argument,
        $deserialize,
        array $metadata = [],
        array $options = [],
    ): Result {
        $context = new CallContext($method, CallContext::UNARY, $metadata, $options, $argument);

        $result = $this->chain()($context, $this->unaryInvoker($deserialize));
        if (!$result instanceof Result) {
            $result = Result::fromTriple(Parser::parseToResultArray(false, $deserialize));
        }

        return $result;
    }

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
        $callOptions = CallOptions::parse($metadata, $options);
        $context     = new CallContext($method, CallContext::UNARY, $metadata, $options, $argument);

        $result = $this->chain()($context, $this->unaryInvoker($deserialize));
        if (!$result instanceof Result) {
            $result = Result::fromTriple(Parser::parseToResultArray(false, $deserialize));
        }

        if ($callOptions->shouldThrowOnError()) {
            $result->throwIfError();
        }

        return $result->toArray();
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
     * Terminal unary invoker used by the interceptor chain: builds the request, sends it, and
     * turns the response into a Result. Options are re-derived from the (possibly rewritten)
     * CallContext so interceptors can inject metadata/timeout/compression.
     *
     * @param mixed $deserialize
     * @return callable(CallContext): Result
     */
    private function unaryInvoker($deserialize): callable
    {
        return function (CallContext $context) use ($deserialize): Result {
            $callOptions      = CallOptions::parse($context->getMetadata(), $context->getOptions());
            $request          = new Request();
            $request->method  = 'POST';
            $request->path    = $context->getMethod();
            $request->data    = self::encodeMessage($context->getArgument(), $callOptions->getCompression());
            $request->headers = array_merge($request->headers, $callOptions->buildHeaders());

            $streamId = $this->send($request);
            $response = self::decodeResponse($this->recv($streamId), $callOptions->getCompression());

            return Result::fromTriple(Parser::parseToResultArray($response, $deserialize));
        };
    }

    /**
     * @param mixed $argument
     */
    private static function encodeMessage($argument, string $compression): string
    {
        // Identity (the default) goes through the original Parser path unchanged.
        $frame = Parser::serializeMessage($argument);
        if ($compression === '' || $compression === 'identity') {
            return $frame;
        }

        return Frame::encode(Parser::unpack($frame), new GzipCompressor());
    }

    /**
     * @param Response|false|null $response
     * @return Response|false|null
     */
    private static function decodeResponse($response, string $compression)
    {
        if ($compression === '' || $compression === 'identity' || !$response instanceof Response || !is_string($response->data)) {
            return $response;
        }
        $data = $response->data;
        if (strlen($data) < Frame::HEADER_LENGTH) {
            return $response;
        }
        $flag = Frame::header($data)[0];
        if ($flag === 0) {
            return $response;
        }

        // Normalize a compressed frame back into an uncompressed one so the untouched
        // Parser::parseToResultArray() can deserialize it.
        $response->data = Parser::pack(Frame::decode($data, [1 => new GzipCompressor()]));
        return $response;
    }

    private function chain(): Chain
    {
        return new Chain($this->interceptors);
    }

    /**
     * Merge call metadata and $options into lowercase HTTP/2 headers. Delegates to CallOptions
     * so -bin encoding, grpc-timeout and compression negotiation are consistently applied, while
     * the default (no opt-in keys) output stays byte-for-byte identical to the original.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private static function buildHeaders(array $metadata, array $options): array
    {
        return CallOptions::parse($metadata, $options)->buildHeaders();
    }
}
