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

use Swoole\Grpc\ClientStreamingCall;
use Swoole\Grpc\Parser;
use Swoole\Grpc\ServerStreamingCall;
use Swoole\Grpc\StreamingCall;
use Swoole\Http2\Response;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\BaseCall
 * @covers \Swoole\Grpc\BidiStreamingCall
 * @covers \Swoole\Grpc\ClientStreamingCall
 * @covers \Swoole\Grpc\ServerStreamingCall
 * @covers \Swoole\Grpc\StreamingCall
 */
class StreamingCallTest extends TestCase
{
    public function testSendOpensPostStreamWithSerializedMessage(): void
    {
        $client = new StreamingFakeClient();
        $call   = self::newCall(StreamingCall::class, $client);
        $call->setHeaders(['x-token' => 'abc']);

        $this->assertTrue($call->send(new StreamingMessage('first')));
        $this->assertSame(5, $call->getStreamId());
        $this->assertCount(1, $client->opened);
        $open = $client->opened[0];
        $this->assertSame('/svc/Method', $open['path']);
        $this->assertSame('POST', $open['method']);
        $this->assertSame(Parser::pack('serialized:first'), $open['data']);
        $this->assertTrue($open['usePipelineRead']);
        $this->assertSame('abc', $open['headers']['x-token']);
    }

    public function testSendTwiceFails(): void
    {
        $client = new StreamingFakeClient();
        $call   = self::newCall(StreamingCall::class, $client);
        $this->assertTrue($call->send(new StreamingMessage('first')));
        $this->assertFalse($call->send(new StreamingMessage('second')));
        $this->assertCount(1, $client->opened);
    }

    public function testPushFirstFrameIsPostWithEmptyData(): void
    {
        $client = new StreamingFakeClient();
        $call   = self::newCall(StreamingCall::class, $client);

        $this->assertTrue($call->push(new StreamingMessage('one')));
        $this->assertCount(1, $client->opened);
        $open = $client->opened[0];
        $this->assertSame('POST', $open['method']);
        $this->assertSame('', $open['data']);
        $this->assertCount(1, $client->writes);
        $this->assertSame(Parser::pack('serialized:one'), $client->writes[0]['data']);
        $this->assertFalse($client->writes[0]['end']);
    }

    public function testPushReusesOpenStream(): void
    {
        $client = new StreamingFakeClient();
        $call   = self::newCall(StreamingCall::class, $client);
        $call->push(new StreamingMessage('one'));
        $call->push(new StreamingMessage('two'));
        $this->assertCount(1, $client->opened);
        $this->assertCount(2, $client->writes);
        $this->assertSame(5, $client->writes[1]['streamId']);
    }

    public function testEndKeepsStreamIdForFinalRecv(): void
    {
        $client = new StreamingFakeClient();
        $call   = self::newCall(StreamingCall::class, $client);
        $call->push(new StreamingMessage('one'));

        $this->assertTrue($call->end());
        // streamId must survive end() so recv() can still read the final response.
        $this->assertSame(5, $call->getStreamId());
        $this->assertTrue($client->writes[1]['end']);
    }

    public function testEndWithoutStreamReturnsFalse(): void
    {
        $call = self::newCall(StreamingCall::class, new StreamingFakeClient());
        $this->assertFalse($call->end());
    }

    public function testRecvWithoutStreamReturnsNoResponse(): void
    {
        $call             = self::newCall(StreamingCall::class, new StreamingFakeClient());
        [$reply, $status] = $call->recv();
        $this->assertSame('No response', $reply);
        $this->assertSame(Parser::ERROR_NO_RESPONSE, $status);
    }

    public function testRecvParsesResponse(): void
    {
        $client               = new StreamingFakeClient();
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = ['grpc-status' => '0'];
        $response->data       = Parser::pack('reply');
        $client->recvResult   = $response;

        $call = self::newCall(StreamingCall::class, $client);
        $call->setStreamId(5);
        [$reply, $status] = $call->recv();
        $this->assertSame('reply', $reply->decoded);
        $this->assertSame(0, $status);
    }

    public function testRecvClearsStreamIdWhenStreamDisappears(): void
    {
        $client               = new StreamingFakeClient();
        $client->streamExists = false;
        $call                 = self::newCall(StreamingCall::class, $client);
        $call->setStreamId(5);
        $call->recv();
        $this->assertSame(0, $call->getStreamId());
    }

    public function testServerStreamingCallRejectsPush(): void
    {
        $call = self::newCall(ServerStreamingCall::class, new StreamingFakeClient());
        $this->expectException(\BadMethodCallException::class);
        $call->push(new StreamingMessage('x'));
    }

    public function testClientStreamingCallRejectsSecondRecv(): void
    {
        $call = self::newCall(ClientStreamingCall::class, new StreamingFakeClient());
        $call->setStreamId(5);
        $call->recv();
        $this->expectException(\LogicException::class);
        $call->recv();
    }

    public function testInitialStateAndRecvResult(): void
    {
        $client               = new StreamingFakeClient();
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = ['grpc-status' => '0'];
        $response->data       = Parser::pack('the-reply');
        $client->recvResult   = $response;

        $call = self::newCall(StreamingCall::class, $client);
        $call->setStreamId(5);
        $this->assertTrue($call->getStatus()->ok());
        $this->assertSame([], $call->getTrailers());
        $this->assertFalse($call->isEnded());

        $result = $call->recvResult();
        $this->assertInstanceOf(\Swoole\Grpc\Result::class, $result);
        $this->assertTrue($result->isOk());
        $this->assertSame(['grpc-status' => '0'], $call->getTrailers());
    }

    public function testIteratorReassemblesMessagesAcrossFragments(): void
    {
        $client = new StreamingFakeClient();
        $frame1 = Parser::pack('one');
        $frame2 = Parser::pack('two');

        // Fragment 1: first frame complete + half of second frame header.
        // Fragment 2: rest of second frame, then a terminal response (pipeline=false).
        $split                = $frame1 . substr($frame2, 0, 3);
        $client->recvSequence = [
            self::makeResponse($split, true, []),
            self::makeResponse(substr($frame2, 3), false, ['grpc-status' => '0']),
        ];

        $call = self::newCall(StreamingCall::class, $client);
        $call->setStreamId(5);

        $values = [];
        foreach ($call as $reply) {
            $this->assertInstanceOf(StreamingReply::class, $reply);
            $values[] = $reply->decoded;
        }
        $this->assertSame(['one', 'two'], $values);
        $this->assertTrue($call->isEnded());
        $this->assertTrue($call->getStatus()->ok());
    }

    public function testIteratorStopsOnTrailersOnly(): void
    {
        $client               = new StreamingFakeClient();
        $client->recvSequence = [
            self::makeResponse('', false, ['grpc-status' => '5', 'grpc-message' => 'missing']),
        ];
        $call = self::newCall(StreamingCall::class, $client);
        $call->setStreamId(5);

        $values = iterator_to_array($call->getIterator());
        $this->assertSame([], $values);
        $this->assertTrue($call->isEnded());
        $this->assertSame(5, $call->getStatus()->getCode());
    }

    public function testCancelMarksEndedWithCancelledStatus(): void
    {
        $client = new StreamingFakeClient();
        $call   = self::newCall(StreamingCall::class, $client);
        $call->setStreamId(5);

        $call->cancel();
        $this->assertTrue($call->isEnded());
        $this->assertSame(\Swoole\Grpc\Status\StatusCode::CANCELLED, $call->getStatus()->getCode());
        // recv() after cancel returns the no-response triple without touching the network.
        [$reply, $status] = $call->recv();
        $this->assertSame('No response', $reply);
        $this->assertSame(Parser::ERROR_NO_RESPONSE, $status);
        // cancel() is idempotent.
        $call->cancel();
        $this->assertSame(\Swoole\Grpc\Status\StatusCode::CANCELLED, $call->getStatus()->getCode());
        $this->assertSame([5], $client->abandoned);
    }

    public function testPushDoesNotWriteWhenTheStreamFailsToOpen(): void
    {
        $client               = new StreamingFakeClient();
        $client->nextStreamId = 0;
        $call                 = self::newCall(StreamingCall::class, $client);

        $this->assertFalse($call->push(new StreamingMessage('one')));
        $this->assertSame([], $client->writes);
        $this->assertSame(0, $call->getStreamId());
    }

    public function testRecvReassemblesASplitMessage(): void
    {
        $client               = new StreamingFakeClient();
        $frame                = Parser::pack('whole');
        $client->recvSequence = [
            self::makeResponse(substr($frame, 0, 3), true, []),
            self::makeResponse(substr($frame, 3), false, ['grpc-status' => '0']),
        ];
        $call = self::newCall(StreamingCall::class, $client);
        $call->setStreamId(5);

        [$reply, $status] = $call->recv();
        $this->assertSame('whole', $reply->decoded);
        $this->assertSame(0, $status);
        $this->assertTrue($call->isEnded());
    }

    public function testRecvTimeoutIsDeadlineExceeded(): void
    {
        $client               = new StreamingFakeClient();
        $client->recvFailure  = \Swoole\Grpc\Client::RECV_TIMEOUT;
        $call                 = self::newCall(StreamingCall::class, $client);
        $call->setStreamId(5);

        [$reply, $status] = $call->recv(0.01);
        $this->assertSame('Deadline exceeded', $reply);
        $this->assertSame(\Swoole\Grpc\Status\StatusCode::DEADLINE_EXCEEDED, $status);
        $this->assertSame(\Swoole\Grpc\Status\StatusCode::DEADLINE_EXCEEDED, $call->getStatus()->getCode());
        $this->assertSame(0.01, $client->lastTimeout);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function makeResponse(string $data, bool $pipeline, array $headers): Response
    {
        $r             = new Response();
        $r->statusCode = 200;
        $r->pipeline   = $pipeline;
        $r->headers    = $headers;
        $r->data       = $data;
        return $r;
    }

    private static function newCall(string $class, StreamingFakeClient $client): StreamingCall
    {
        $call = new $class();
        $call->setClient($client);
        $call->setMethod('/svc/Method');
        $call->setDeserialize([StreamingReply::class, 'mergeFromString']);
        return $call;
    }
}

/** @internal */
class StreamingMessage
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
class StreamingReply
{
    public string $decoded = '';

    public function mergeFromString(string $data): void
    {
        $this->decoded = $data;
    }
}

/** @internal */
class StreamingFakeClient
{
    public array $opened = [];

    public array $writes = [];

    public int $nextStreamId = 5;

    public bool $streamExists = true;

    public $recvResult = false;

    /** @var array<int, mixed> queued responses consumed by recv() one at a time */
    public array $recvSequence = [];

    public array $abandoned = [];

    public int $recvFailure = \Swoole\Grpc\Client::RECV_OK;

    public ?float $lastTimeout = null;

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

    public function recv(int $streamId, ?float $timeout = null)
    {
        $this->lastTimeout = $timeout;
        if ($this->recvSequence !== []) {
            return array_shift($this->recvSequence);
        }
        return $this->recvResult;
    }

    public function consumeRecvFailure(int $streamId): int
    {
        return $this->recvFailure;
    }

    public function abandon(int $streamId): void
    {
        $this->abandoned[] = $streamId;
    }

    public function isStreamExist(int $streamId): bool
    {
        return $this->streamExists;
    }
}
