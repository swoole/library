<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Grpc\Status;

use Swoole\Grpc\Status\RpcException;
use Swoole\Grpc\Status\Status;
use Swoole\Grpc\Status\StatusCode;
use Swoole\Http2\Response;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Status\RpcException
 * @covers \Swoole\Grpc\Status\Status
 */
class StatusTest extends TestCase
{
    public function testDefaults(): void
    {
        $status = new Status();
        $this->assertSame(StatusCode::OK, $status->getCode());
        $this->assertSame('', $status->getMessage());
        $this->assertSame([], $status->getTrailers());
        $this->assertTrue($status->ok());
    }

    public function testConstructedValue(): void
    {
        $status = new Status(StatusCode::NOT_FOUND, 'missing', ['grpc-status' => '5']);
        $this->assertSame(StatusCode::NOT_FOUND, $status->getCode());
        $this->assertSame('NOT_FOUND', $status->getName());
        $this->assertSame('missing', $status->getMessage());
        $this->assertSame(['grpc-status' => '5'], $status->getTrailers());
        $this->assertFalse($status->ok());
    }

    public function testFromResponseSuccess(): void
    {
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = ['grpc-status' => '0', 'grpc-message' => ''];
        $status               = Status::fromResponse($response);
        $this->assertTrue($status->ok());
        $this->assertSame(StatusCode::OK, $status->getCode());
    }

    public function testFromResponseGrpcError(): void
    {
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = ['grpc-status' => '12', 'grpc-message' => 'unimplemented%20method'];
        $status               = Status::fromResponse($response);
        $this->assertFalse($status->ok());
        $this->assertSame(StatusCode::UNIMPLEMENTED, $status->getCode());
        $this->assertSame('unimplemented method', $status->getMessage());
        $this->assertFalse($status->isRetryable());
    }

    public function testFromResponseNoResponse(): void
    {
        $status = Status::fromResponse(false);
        $this->assertSame(StatusCode::UNKNOWN, $status->getCode());
        $this->assertSame('No response', $status->getMessage());
    }

    public function testFromResponseHttpError(): void
    {
        $response             = new Response();
        $response->statusCode = 503;
        $response->headers    = [];
        $status               = Status::fromResponse($response);
        $this->assertSame(StatusCode::UNKNOWN, $status->getCode());
        $this->assertSame('Http status Error', $status->getMessage());
    }

    public function testFromResponsePrefersTrailersProperty(): void
    {
        // Emulate the enhanced Swoole extension: a dedicated trailers property.
        $response = new class extends Response {
            /** @var array<string, string> */
            public $trailers = ['grpc-status' => '7', 'grpc-message' => 'denied'];
        };
        $response->statusCode = 200;
        $response->headers    = ['grpc-status' => '0'];
        $status               = Status::fromResponse($response);
        $this->assertSame(StatusCode::PERMISSION_DENIED, $status->getCode());
        $this->assertSame('denied', $status->getMessage());
    }

    public function testThrowIfError(): void
    {
        $ok = new Status();
        $this->assertSame($ok, $ok->throwIfError());

        $error = new Status(StatusCode::UNAVAILABLE, 'down');
        try {
            $error->throwIfError();
            $this->fail('Expected RpcException');
        } catch (RpcException $e) {
            $this->assertSame(StatusCode::UNAVAILABLE, $e->getStatusCode());
            $this->assertSame($error, $e->getStatus());
            $this->assertStringContainsString('UNAVAILABLE', $e->getMessage());
        }
    }

    public function testToException(): void
    {
        $status = new Status(StatusCode::INTERNAL, 'boom');
        $e      = $status->toException();
        $this->assertInstanceOf(RpcException::class, $e);
        $this->assertSame(StatusCode::INTERNAL, $e->getCode());
    }
}
