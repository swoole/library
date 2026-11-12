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

use Swoole\Grpc\Result;
use Swoole\Grpc\Status\RpcException;
use Swoole\Grpc\Status\StatusCode;
use Swoole\Http2\Response;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Result
 */
class ResultTest extends TestCase
{
    public function testFromTripleSuccess(): void
    {
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = ['grpc-status' => '0'];
        $result               = Result::fromTriple(['reply', 0, $response]);
        $this->assertSame('reply', $result->getReply());
        $this->assertSame(0, $result->getStatusCode());
        $this->assertSame($response, $result->getResponse());
        $this->assertTrue($result->isOk());
        $this->assertSame(StatusCode::OK, $result->getStatus()->getCode());
        $this->assertSame(['reply', 0, $response], $result->toArray());
    }

    public function testFromTripleError(): void
    {
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = ['grpc-status' => '5', 'grpc-message' => 'not%20found'];
        $result               = Result::fromTriple(['not found', 5, $response]);
        $this->assertFalse($result->isOk());
        $this->assertSame(StatusCode::NOT_FOUND, $result->getStatus()->getCode());
        $this->assertSame('not found', $result->getStatus()->getMessage());
        $this->assertSame(['grpc-status' => '5', 'grpc-message' => 'not%20found'], $result->getTrailers());
    }

    public function testLocalErrorMapsToUnknown(): void
    {
        $result = Result::fromTriple(['No response', -1, false]);
        $status = $result->getStatus();
        $this->assertSame(StatusCode::UNKNOWN, $status->getCode());
        $this->assertSame('No response', $status->getMessage());
    }

    public function testThrowIfError(): void
    {
        $ok = Result::fromTriple(['x', 0, null]);
        $this->assertSame($ok, $ok->throwIfError());

        $err = Result::fromTriple(['down', StatusCode::UNAVAILABLE, null]);
        $this->expectException(RpcException::class);
        $err->throwIfError();
    }

    public function testToException(): void
    {
        $result = Result::fromTriple(['x', StatusCode::INTERNAL, null]);
        $e      = $result->toException();
        $this->assertInstanceOf(RpcException::class, $e);
        $this->assertSame(StatusCode::INTERNAL, $e->getStatusCode());
    }
}
