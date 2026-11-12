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

use Swoole\Grpc\Compression\GzipCompressor;
use Swoole\Grpc\Internal\Frame;
use Swoole\Grpc\Parser;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Internal\Frame
 */
class FrameTest extends TestCase
{
    public function testEncodeIdentityMatchesParserPack(): void
    {
        // Identity Frame::encode must be byte-identical to the original Parser::pack.
        $this->assertSame(Parser::pack('hello'), Frame::encode('hello'));
    }

    public function testHeader(): void
    {
        $frame = Parser::pack('hello');
        $this->assertSame([0, 5, 10], Frame::header($frame));
    }

    public function testHeaderRejectsAnOversizedMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Frame::header(pack('CN', 0, Frame::DEFAULT_MAX_MESSAGE_BYTES + 1));
    }

    public function testHeaderTooShort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Frame::header('abc');
    }

    public function testDecodeIdentity(): void
    {
        $this->assertSame('hello', Frame::decode(Parser::pack('hello')));
    }

    public function testDecodeIncompletePayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Frame::decode(pack('CN', 0, 10) . 'short');
    }

    public function testGzipFrameRoundTrip(): void
    {
        if (!function_exists('gzencode')) {
            $this->markTestSkipped('ext-zlib required');
        }
        $gzip    = new GzipCompressor();
        $payload = str_repeat('payload', 500);
        $frame   = Frame::encode($payload, $gzip);
        [$flag]  = Frame::header($frame);
        $this->assertSame(1, $flag);
        $this->assertSame($payload, Frame::decode($frame, [1 => $gzip]));
    }

    public function testDecodeCompressedWithoutDecompressor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Frame::decode(pack('CN', 1, 3) . 'abc');
    }
}
