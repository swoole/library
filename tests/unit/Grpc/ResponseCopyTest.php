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

use Swoole\Grpc\Internal\ResponseCopy;
use Swoole\Grpc\Parser;
use Swoole\Http2\Response;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Internal\ResponseCopy
 */
class ResponseCopyTest extends TestCase
{
    public function testWithFrameReplacesDataAndLeavesTheOriginalBuffer(): void
    {
        $frame                        = Parser::pack('one');
        $original                     = $frame . Parser::pack('two');
        $response                     = new Response();
        $response->streamId           = 9;
        $response->errCode            = 0;
        $response->statusCode         = 200;
        $response->pipeline           = false;
        $response->headers            = ['grpc-status' => '0'];
        $response->set_cookie_headers = ['a=b'];
        $response->cookies            = ['a' => 'b'];
        $response->data               = $original;
        if (property_exists($response, 'trailers')) {
            $response->trailers = ['x-user' => 'ada'];
        }

        $copy = ResponseCopy::withFrame($response, $frame);

        $this->assertNotSame($response, $copy);
        $this->assertSame($frame, $copy->data);
        $this->assertSame($original, $response->data);
        $this->assertSame(9, $copy->streamId);
        $this->assertSame(0, $copy->errCode);
        $this->assertSame(200, $copy->statusCode);
        $this->assertFalse($copy->pipeline);
        $this->assertSame(['grpc-status' => '0'], $copy->headers);
        $this->assertSame(['a=b'], $copy->set_cookie_headers);
        $this->assertSame(['a' => 'b'], $copy->cookies);
        if (property_exists($response, 'trailers')) {
            $this->assertSame(['x-user' => 'ada'], $copy->trailers);
        } else {
            $this->assertFalse(property_exists($copy, 'trailers'));
        }
    }

    public function testADeclaredPropertyIsCopied(): void
    {
        $from = new class {
            public array $trailers = ['grpc-status' => '0'];
        };
        $to = new class {
            public array $trailers = [];
        };

        ResponseCopy::copyIfPresent($from, $to, 'trailers');

        $this->assertSame(['grpc-status' => '0'], $to->trailers);
    }

    /**
     * Swoole 6.1 and 6.2 do not declare Response::$trailers. Writing the name anyway would create
     * a dynamic property.
     */
    public function testAPropertyMissingOnEitherSideIsNotCreated(): void
    {
        $from = new class {
            public string $data = 'chunk';
        };
        $to = new class {
            public string $data = '';
        };
        $newer = new class {
            public array $trailers = ['grpc-status' => '0'];
        };

        ResponseCopy::copyIfPresent($from, $to, 'trailers');
        ResponseCopy::copyIfPresent($newer, $to, 'trailers');

        $this->assertFalse(property_exists($to, 'trailers'));
        $this->assertSame('', $to->data);
        $this->assertSame(['grpc-status' => '0'], $newer->trailers);
    }
}
