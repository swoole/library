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

use Swoole\Grpc\Request;
use Swoole\Http2\Request as Http2Request;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Request
 */
class RequestTest extends TestCase
{
    public function testExtendsHttp2Request(): void
    {
        $this->assertInstanceOf(Http2Request::class, new Request());
    }

    public function testDefaultGrpcHeaders(): void
    {
        $request = new Request();
        $this->assertSame('application/grpc', $request->headers['content-type']);
        $this->assertSame('trailers', $request->headers['te']);
        $this->assertSame('grpc-swoole', $request->headers['user-agent']);
    }

    public function testEachInstanceGetsFreshHeaders(): void
    {
        $first                      = new Request();
        $first->headers['x-custom'] = 'value';
        $second                     = new Request();
        $this->assertArrayNotHasKey('x-custom', $second->headers);
    }

    public function testHeadersCanBeOverridden(): void
    {
        $request          = new Request();
        $request->headers = array_merge($request->headers, ['authorization' => 'Bearer token']);
        $this->assertSame('Bearer token', $request->headers['authorization']);
        $this->assertSame('application/grpc', $request->headers['content-type']);
    }
}
