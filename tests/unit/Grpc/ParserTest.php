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

use Swoole\Grpc\Parser;
use Swoole\Http2\Response;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Parser
 */
class ParserTest extends TestCase
{
    public function testPackUnpackRoundTrip(): void
    {
        foreach (['', 'hello', str_repeat('x', 1024), "\x00\xff\x01binary"] as $payload) {
            $frame = Parser::pack($payload);
            $this->assertSame(5 + strlen($payload), strlen($frame));
            $this->assertSame("\x00", $frame[0]); // compression flag = 0
            $this->assertSame($payload, Parser::unpack($frame));
        }
    }

    public function testPackLengthPrefix(): void
    {
        $frame  = Parser::pack('abc');
        $header = unpack('Ccompression/Nlength', substr($frame, 0, 5));
        $this->assertSame(0, $header['compression']);
        $this->assertSame(3, $header['length']);
    }

    public function testUnpackRejectsShortFrame(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Parser::unpack('abcd');
    }

    public function testUnpackRejectsMismatchedLengthPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Parser::unpack(pack('CN', 0, 99) . 'short');
    }

    public function testSerializeMessageViaEncode(): void
    {
        $message = new class {
            public function encode(): string
            {
                return 'encoded';
            }
        };
        $this->assertSame(Parser::pack('encoded'), Parser::serializeMessage($message));
    }

    public function testSerializeMessageViaSerializeToString(): void
    {
        $message = new class {
            public function serializeToString(): string
            {
                return 'proto';
            }
        };
        $this->assertSame(Parser::pack('proto'), Parser::serializeMessage($message));
    }

    public function testSerializeMessageViaSerialize(): void
    {
        $message = new class {
            public function serialize(): string
            {
                return 'legacy';
            }
        };
        $this->assertSame(Parser::pack('legacy'), Parser::serializeMessage($message));
    }

    public function testSerializeMessageRejectsUnsupportedObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Parser::serializeMessage(new \stdClass());
    }

    public function testSerializeMessageRejectsNonObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Parser::serializeMessage('a string');
    }

    public function testSerializeMessageRejectsNonStringResult(): void
    {
        $message = new class {
            public function encode()
            {
                return 42;
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        Parser::serializeMessage($message);
    }

    public function testDeserializeMessageReturnsNullForEmpty(): void
    {
        $this->assertNull(Parser::deserializeMessage([ReplyMessage::class, null], null));
        $this->assertNull(Parser::deserializeMessage([ReplyMessage::class, null], ''));
    }

    public function testDeserializeMessageWithClassNameAndMethod(): void
    {
        $reply = Parser::deserializeMessage([ReplyMessage::class, 'mergeFromString'], Parser::pack('payload'));
        $this->assertInstanceOf(ReplyMessage::class, $reply);
        $this->assertSame('payload', $reply->decoded);
    }

    public function testDeserializeMessageFallsBackToMergeFromString(): void
    {
        $reply = Parser::deserializeMessage([ReplyMessage::class, null], Parser::pack('bytes'));
        $this->assertSame('bytes', $reply->decoded);
    }

    public function testDeserializeMessageWithUnknownMethodFallsBack(): void
    {
        $reply = Parser::deserializeMessage([ReplyMessage::class, 'decode'], Parser::pack('data'));
        $this->assertSame('data', $reply->decoded);
    }

    public function testDeserializeMessageWithCallable(): void
    {
        $result = Parser::deserializeMessage(
            static fn (string $value): string => strtoupper($value),
            Parser::pack('abc')
        );
        $this->assertSame('ABC', $result);
    }

    public function testDeserializeMessageRejectsInvalidClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Parser::deserializeMessage(['Not\A\Real\Class', null], Parser::pack('x'));
    }

    public function testDeserializeMessageRejectsInvalidCallable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Parser::deserializeMessage(123, Parser::pack('x'));
    }

    public function testParseToResultArrayNoResponse(): void
    {
        [$reply, $status, $raw] = Parser::parseToResultArray(false, [ReplyMessage::class, null]);
        $this->assertSame('No response', $reply);
        $this->assertSame(Parser::ERROR_NO_RESPONSE, $status);
        $this->assertFalse($raw);
    }

    public function testParseToResultArrayHttpError(): void
    {
        $response               = self::makeResponse(503, [], null, 7);
        [$reply, $status, $raw] = Parser::parseToResultArray($response, [ReplyMessage::class, null]);
        $this->assertSame('Http status Error', $reply);
        $this->assertSame(7, $status); // errCode takes precedence over statusCode
        $this->assertSame($response, $raw);
    }

    public function testParseToResultArrayHttpErrorUsesStatusCodeWhenNoErrCode(): void
    {
        $response   = self::makeResponse(404, [], null, 0);
        [, $status] = Parser::parseToResultArray($response, [ReplyMessage::class, null]);
        $this->assertSame(404, $status);
    }

    public function testParseToResultArrayGrpcError(): void
    {
        $response         = self::makeResponse(200, ['grpc-status' => '5', 'grpc-message' => 'not found'], null, 0);
        [$reply, $status] = Parser::parseToResultArray($response, [ReplyMessage::class, null]);
        $this->assertSame('not found', $reply);
        $this->assertSame(5, $status);
    }

    public function testParseToResultArrayGrpcErrorWithoutMessage(): void
    {
        $response         = self::makeResponse(200, ['grpc-status' => '13'], null, 0);
        [$reply, $status] = Parser::parseToResultArray($response, [ReplyMessage::class, null]);
        $this->assertSame('Unknown error', $reply);
        $this->assertSame(13, $status);
    }

    public function testParseToResultArraySuccess(): void
    {
        $response               = self::makeResponse(200, ['grpc-status' => '0'], Parser::pack('reply-bytes'), 0);
        [$reply, $status, $raw] = Parser::parseToResultArray($response, [ReplyMessage::class, 'mergeFromString']);
        $this->assertInstanceOf(ReplyMessage::class, $reply);
        $this->assertSame('reply-bytes', $reply->decoded);
        $this->assertSame(0, $status);
        $this->assertSame($response, $raw);
    }

    public function testParseToResultArrayAccepts2xxFamily(): void
    {
        foreach ([200, 201, 204, 299] as $code) {
            $response   = self::makeResponse($code, ['grpc-status' => '0'], Parser::pack('ok'), 0);
            [, $status] = Parser::parseToResultArray($response, [ReplyMessage::class, null]);
            $this->assertSame(0, $status, "status {$code} should be treated as success");
        }
    }

    public function testParseToResultArrayPipelineDataFrameWithZeroStatus(): void
    {
        // Streaming DATA frames carry statusCode 0; they must still be parsed as success.
        $response         = self::makeResponse(0, [], Parser::pack('stream-chunk'), 0);
        [$reply, $status] = Parser::parseToResultArray($response, [ReplyMessage::class, null]);
        $this->assertSame('stream-chunk', $reply->decoded);
        $this->assertSame(0, $status);
    }

    public function testParseToResultArrayWithNullHeaders(): void
    {
        $response             = new Response();
        $response->statusCode = 200;
        $response->data       = Parser::pack('x');
        [$reply, $status]     = Parser::parseToResultArray($response, [ReplyMessage::class, null]);
        $this->assertSame('x', $reply->decoded);
        $this->assertSame(0, $status);
    }

    private static function makeResponse(int $statusCode, array $headers, ?string $data, int $errCode): Response
    {
        $response             = new Response();
        $response->statusCode = $statusCode;
        $response->headers    = $headers;
        $response->data       = $data;
        $response->errCode    = $errCode;
        return $response;
    }
}

/**
 * Minimal protobuf-like message used as a deserialize target.
 */
class ReplyMessage
{
    public string $decoded = '';

    public function mergeFromString(string $data): void
    {
        $this->decoded = $data;
    }
}
