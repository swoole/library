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

use Swoole\Grpc\Status\Status;
use Swoole\Grpc\Status\StatusCode;
use Swoole\Http2\Response;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Status\Status
 */
class StatusFromResponseTest extends TestCase
{
    public function testSuccessFromHeadersFallback(): void
    {
        // Stock Swoole: grpc-status merged into headers, no trailers property.
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = ['grpc-status' => '0'];
        $status               = Status::fromResponse($response);
        $this->assertTrue($status->ok());
        $this->assertSame(['grpc-status' => '0'], $status->getTrailers());
    }

    public function testErrorFromTrailersProperty(): void
    {
        // Enhanced Swoole: dedicated trailers property takes precedence.
        $response = new class extends Response {
            /** @var array<string, string> */
            public $trailers = ['grpc-status' => '14', 'grpc-message' => 'unavailable'];
        };
        $response->statusCode = 200;
        $response->headers    = ['content-type' => 'application/grpc'];
        $status               = Status::fromResponse($response);
        $this->assertSame(StatusCode::UNAVAILABLE, $status->getCode());
        $this->assertTrue($status->isRetryable());
        $this->assertSame('unavailable', $status->getMessage());
    }

    public function testTrailersOnlySingleFrame(): void
    {
        // A single HEADERS frame: no trailers property, grpc-status lives in headers.
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = [
            'content-type' => 'application/grpc',
            'grpc-status'  => '12',
            'grpc-message' => 'unimplemented%20method',
        ];
        $status = Status::fromResponse($response);
        $this->assertSame(StatusCode::UNIMPLEMENTED, $status->getCode());
        $this->assertSame('unimplemented method', $status->getMessage());
    }

    public function testStreamingDataFrameWithNoGrpcStatus(): void
    {
        // Intermediate streaming DATA frame: statusCode 0, no grpc-status -> OK.
        $response             = new Response();
        $response->statusCode = 0;
        $response->headers    = [];
        $response->data       = 'frame';
        $this->assertTrue(Status::fromResponse($response)->ok());
    }
}
