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

use Swoole\Grpc\Internal\Trailers;
use Swoole\Http2\Response;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Internal\Trailers
 */
class TrailersTest extends TestCase
{
    public function testFallbackFromHeadersOnStockSwoole(): void
    {
        // Stock Swoole: no trailers property, grpc-* headers merged into headers.
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = [
            'content-type' => 'application/grpc',
            'grpc-status'  => '5',
            'grpc-message' => 'not%20found',
        ];
        $this->assertSame(
            ['grpc-status' => '5', 'grpc-message' => 'not%20found'],
            Trailers::fromResponse($response)
        );
        $this->assertSame(5, Trailers::statusCode($response));
        $this->assertSame('not found', Trailers::statusMessage($response));
    }

    public function testPrefersTrailersPropertyOnEnhancedSwoole(): void
    {
        $response = new class extends Response {
            /** @var array<string, string> */
            public $trailers = ['grpc-status' => '12', 'grpc-message' => 'unimpl'];
        };
        $response->statusCode = 200;
        $response->headers    = ['grpc-status' => '0'];
        $this->assertSame(
            ['grpc-status' => '12', 'grpc-message' => 'unimpl'],
            Trailers::fromResponse($response)
        );
        $this->assertSame(12, Trailers::statusCode($response));
    }

    public function testFallsBackToHeadersWhenTrailersIsEmpty(): void
    {
        // trailers property exists but is empty (single HEADERS frame / Trailers-Only): the
        // grpc-* status still lives in headers and must be picked up.
        $response = new class extends Response {
            /** @var array<string, string>|null */
            public $trailers = [];
        };
        $response->statusCode = 200;
        $response->headers    = ['grpc-status' => '13', 'grpc-message' => 'oops'];
        $this->assertSame(13, Trailers::statusCode($response));
        $this->assertSame('oops', Trailers::statusMessage($response));
    }

    public function testArrayHeaderValuesAreJoined(): void
    {
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = ['grpc-status' => ['0']];
        $this->assertSame(['grpc-status' => '0'], Trailers::fromResponse($response));
    }

    public function testNoResponse(): void
    {
        $this->assertSame([], Trailers::fromResponse(false));
        $this->assertSame([], Trailers::fromResponse(null));
        $this->assertNull(Trailers::statusCode(false));
        $this->assertSame('', Trailers::statusMessage(false));
    }

    public function testMissingGrpcStatus(): void
    {
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = ['content-type' => 'application/grpc'];
        $this->assertSame([], Trailers::fromResponse($response));
        $this->assertNull(Trailers::statusCode($response));
    }

    public function testMessageIsPercentDecoded(): void
    {
        $response             = new Response();
        $response->statusCode = 200;
        $response->headers    = ['grpc-message' => 'bad%20request%3A%20x'];
        $this->assertSame('bad request: x', Trailers::statusMessage($response));
    }
}
