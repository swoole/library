<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Grpc;

use Swoole\Grpc\BaseStub;
use Swoole\Grpc\BidiStreamingCall;
use Swoole\Grpc\ClientStreamingCall;
use Swoole\Grpc\Parser;
use Swoole\Grpc\Request;
use Swoole\Grpc\ServerStreamingCall;
use Swoole\Http2\Response;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\BaseStub
 */
class BaseStubTest extends TestCase
{
    public function testSimpleRequestBuildsPostRequest(): void
    {
        $stub             = new RecordingStub();
        [$reply, $status] = $stub->simple('/helloworld.Greeter/SayHello', new StubMessage('req'));

        $this->assertSame('POST', $stub->lastRequest->method);
        $this->assertSame('/helloworld.Greeter/SayHello', $stub->lastRequest->path);
        $this->assertSame(Parser::pack('serialized:req'), $stub->lastRequest->data);
        // With no recv result configured, parseToResultArray reports "No response".
        $this->assertSame('No response', $reply);
        $this->assertSame(Parser::ERROR_NO_RESPONSE, $status);
    }

    public function testSimpleRequestMergesMetadataAndOptionHeaders(): void
    {
        $stub = new RecordingStub();
        $stub->simple(
            '/m',
            new StubMessage('req'),
            ['X-Token' => 'abc'],
            ['headers' => ['x-opt' => 'opt-value', 'Content-Type' => 'application/grpc+proto']]
        );

        $headers = $stub->lastRequest->headers;
        $this->assertSame('abc', $headers['x-token']);
        $this->assertSame('opt-value', $headers['x-opt']);
        $this->assertSame('application/grpc+proto', $headers['content-type']);
        $this->assertSame('trailers', $headers['te']); // default preserved
    }

    public function testSimpleRequestDeserializesRecvResponse(): void
    {
        $stub                 = new RecordingStub();
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = ['grpc-status' => '0'];
        $response->data       = Parser::pack('the-reply');
        $stub->recvResult     = $response;

        [$reply, $status] = $stub->simple('/m', new StubMessage('req'));
        $this->assertInstanceOf(StubReply::class, $reply);
        $this->assertSame('the-reply', $reply->decoded);
        $this->assertSame(0, $status);
    }

    public function testServerStreamRequestSendsFirstMessage(): void
    {
        $stub = new RecordingStub();
        $stub->setDefaultHeaders(['x-call' => '1']);
        $call = $stub->serverStream('/svc/Stream', new StubMessage('first'));

        $this->assertInstanceOf(ServerStreamingCall::class, $call);
        $this->assertSame(9, $call->getStreamId());
        $this->assertCount(1, $stub->opened);
        $open = $stub->opened[0];
        $this->assertSame('/svc/Stream', $open['path']);
        $this->assertSame('POST', $open['method']);
        $this->assertSame(Parser::pack('serialized:first'), $open['data']);
        $this->assertSame('1', $open['headers']['x-call']);
    }

    public function testClientStreamRequestDoesNotOpenImmediately(): void
    {
        $stub = new RecordingStub();
        $call = $stub->clientStream('/svc/Upload');
        $this->assertInstanceOf(ClientStreamingCall::class, $call);
        $this->assertSame(0, $call->getStreamId());
        $this->assertSame([], $stub->opened);
        $this->assertSame([], $stub->writes);
    }

    public function testBidiRequestDoesNotOpenImmediately(): void
    {
        $stub = new RecordingStub();
        $call = $stub->bidi('/svc/Chat');
        $this->assertInstanceOf(BidiStreamingCall::class, $call);
        $this->assertSame(0, $call->getStreamId());
        $this->assertSame([], $stub->opened);
    }

    public function testMetadataPropagatesToBidiFirstPush(): void
    {
        $stub = new RecordingStub();
        $call = $stub->bidi('/svc/Chat', ['X-Token' => 'tok']);
        $call->push(new StubMessage('hello'));
        $this->assertCount(1, $stub->opened);
        $this->assertSame('tok', $stub->opened[0]['headers']['x-token']);
    }
}

/** @internal */
class StubMessage
{
    public function __construct(private string $value)
    {
    }

    public function serializeToString(): string
    {
        return 'serialized:' . $this->value;
    }
}

/** @internal */
class StubReply
{
    public string $decoded = '';

    public function mergeFromString(string $data): void
    {
        $this->decoded = $data;
    }
}

/** @internal */
class RecordingStub extends BaseStub
{
    public ?Request $lastRequest = null;

    public array $opened = [];

    public array $writes = [];

    public int $nextStreamId = 9;

    public bool $streamExists = true;

    public $recvResult = false;

    private array $defaultHeaders = [];

    public function __construct()
    {
        // Do not call parent: it would try to connect a real Client.
    }

    public function setDefaultHeaders(array $headers): void
    {
        $this->defaultHeaders = $headers;
    }

    public function simple(string $method, $argument, array $metadata = [], array $options = []): array
    {
        return $this->_simpleRequest($method, $argument, [StubReply::class, 'mergeFromString'], $metadata, $options);
    }

    public function serverStream(string $method, $argument, array $metadata = [], array $options = []): ServerStreamingCall
    {
        if ($this->defaultHeaders) {
            $metadata = array_merge($this->defaultHeaders, $metadata);
        }
        return $this->_serverStreamRequest($method, $argument, [StubReply::class, 'mergeFromString'], $metadata, $options);
    }

    public function clientStream(string $method, array $metadata = [], array $options = []): ClientStreamingCall
    {
        return $this->_clientStreamRequest($method, [StubReply::class, 'mergeFromString'], $metadata, $options);
    }

    public function bidi(string $method, array $metadata = [], array $options = []): BidiStreamingCall
    {
        return $this->_bidiRequest($method, [StubReply::class, 'mergeFromString'], $metadata, $options);
    }

    public function send(Request $request): int
    {
        $this->lastRequest = $request;
        return $this->nextStreamId;
    }

    public function recv(int $streamId, ?float $timeout = null)
    {
        return $this->recvResult;
    }

    public function openStream(
        string $path,
        ?string $data = '',
        string $method = '',
        bool $usePipelineRead = false,
        array $headers = [],
    ): int {
        $this->opened[] = compact('path', 'data', 'method', 'usePipelineRead', 'headers');
        return $this->nextStreamId;
    }

    public function write(int $streamId, ?string $data, bool $end = false): bool
    {
        $this->writes[] = compact('streamId', 'data', 'end');
        return true;
    }

    public function isStreamExist(int $streamId): bool
    {
        return $this->streamExists;
    }
}
