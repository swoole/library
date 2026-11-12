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
use Swoole\Grpc\Internal\FrameReassembler;
use Swoole\Grpc\Parser;
use Swoole\Http2\Response;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Internal\FrameReassembler
 */
class FrameReassemblerTest extends TestCase
{
    public function testSingleCompleteFrame(): void
    {
        $r        = new FrameReassembler();
        $response = self::response(Parser::pack('hello'), true);
        $out      = $r->push($response);
        $this->assertCount(1, $out['frames']);
        $this->assertSame('hello', Parser::unpack($out['frames'][0]));
        $this->assertFalse($out['done']); // pipeline=true means more to come
    }

    public function testFrameSplitAcrossFragments(): void
    {
        $r      = new FrameReassembler();
        $frame  = Parser::pack('hello world');
        $first  = self::response(substr($frame, 0, 3), true);
        $second = self::response(substr($frame, 3), true);

        $this->assertSame([], $r->push($first)['frames']); // only a partial header
        $out = $r->push($second);
        $this->assertCount(1, $out['frames']);
        $this->assertSame('hello world', Parser::unpack($out['frames'][0]));
    }

    public function testTwoFramesInOneFragment(): void
    {
        $r    = new FrameReassembler();
        $data = Parser::pack('one') . Parser::pack('two');
        $out  = $r->push(self::response($data, true));
        $this->assertCount(2, $out['frames']);
        $this->assertSame('one', Parser::unpack($out['frames'][0]));
        $this->assertSame('two', Parser::unpack($out['frames'][1]));
    }

    public function testFrameHeaderSplitAcrossFragments(): void
    {
        $r     = new FrameReassembler();
        $frame = Parser::pack('payload');
        // Split inside the 5-byte header.
        $this->assertSame([], $r->push(self::response(substr($frame, 0, 2), true))['frames']);
        $out = $r->push(self::response(substr($frame, 2), true));
        $this->assertCount(1, $out['frames']);
        $this->assertSame('payload', Parser::unpack($out['frames'][0]));
    }

    public function testTerminalPipelineFalse(): void
    {
        $r        = new FrameReassembler();
        $response = self::response(Parser::pack('last'), false, ['grpc-status' => '0']);
        $out      = $r->push($response);
        $this->assertCount(1, $out['frames']);
        $this->assertTrue($out['done']);
        $this->assertTrue($r->isDone());
    }

    public function testTrailersOnly(): void
    {
        $r = new FrameReassembler();
        // No data, but grpc-status present in the (single) HEADERS frame -> end with no frames.
        $response = self::response('', false, ['grpc-status' => '5', 'grpc-message' => 'not found']);
        $out      = $r->push($response);
        $this->assertSame([], $out['frames']);
        $this->assertTrue($out['done']);
    }

    public function testFalseResponseEndsStream(): void
    {
        $r   = new FrameReassembler();
        $out = $r->push(false);
        $this->assertTrue($out['done']);
        $this->assertSame([], $out['frames']);
    }

    public function testCompressedFrameDecoded(): void
    {
        if (!function_exists('gzencode')) {
            $this->markTestSkipped('ext-zlib required');
        }
        $gzip  = new GzipCompressor();
        $r     = new FrameReassembler([1 => $gzip]);
        $frame = Frame::encode('compressed-payload', $gzip);
        // Split the compressed frame across two fragments.
        $first  = self::response(substr($frame, 0, 7), true);
        $second = self::response(substr($frame, 7), false, ['grpc-status' => '0']);
        $r->push($first);
        $out = $r->push($second);
        $this->assertCount(1, $out['frames']);
        $this->assertSame('compressed-payload', Parser::unpack($out['frames'][0]));
        $this->assertTrue($out['done']);
    }

    public function testResetClearsBufferAndState(): void
    {
        $r     = new FrameReassembler();
        $frame = Parser::pack('data');
        $r->push(self::response(substr($frame, 0, 4), true));
        $r->reset();
        $this->assertFalse($r->isDone());
        $out = $r->push(self::response(Parser::pack('fresh'), true));
        $this->assertCount(1, $out['frames']);
        $this->assertSame('fresh', Parser::unpack($out['frames'][0]));
    }

    /**
     * @param array<string, string> $headers
     */
    private static function response(string $data, bool $pipeline, array $headers = []): Response
    {
        $r             = new Response();
        $r->statusCode = 200;
        $r->pipeline   = $pipeline;
        $r->headers    = $headers;
        $r->data       = $data;
        return $r;
    }
}
