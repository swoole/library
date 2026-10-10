<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\FastCGI;

use Swoole\FastCGI\Record\BeginRequest;
use Swoole\FastCGI\Record\Params;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\FastCGI\FrameParser
 */
class FrameParserTest extends TestCase
{
    /** @dataProvider incompleteFrames */
    public function testIncompleteFramesAreRejected(string $frame): void
    {
        self::assertFalse(FrameParser::hasFrame($frame));
        $this->expectException(\RuntimeException::class);
        FrameParser::parseFrame($frame);
    }

    public static function incompleteFrames(): array
    {
        $frame = "\x01\x06\x00\x01\x00\x03\x01\x00abc\0";
        return [[''], [substr($frame, 0, 7)], [substr($frame, 0, 10)], [substr($frame, 0, -1)]];
    }

    public function testHasFrame(): void
    {
        /** @var string $incompletePacket */
        $incompletePacket = hex2bin('010100010008000000');
        $this->assertFalse(FrameParser::hasFrame($incompletePacket));

        /** @var string $completePacket */
        $completePacket = hex2bin('01010001000800000001010000000000');
        $this->assertTrue(FrameParser::hasFrame($completePacket));
    }

    public function testParsingFrame(): void
    {
        // one FCGI_BEGIN request with two empty FCGI_PARAMS request
        /** @var string $dataStream */
        $dataStream = hex2bin('0101000100080000000101000000000001040001000000000104000100000000');
        $bufferSize = strlen($dataStream);
        $this->assertEquals(32, $bufferSize);

        // consume FCGI_BEGIN request
        $record = FrameParser::parseFrame($dataStream);
        $this->assertInstanceOf(BeginRequest::class, $record);
        $recordSize = strlen((string) $record);
        $this->assertEquals(16, $recordSize);

        $this->assertEquals($bufferSize - $recordSize, strlen($dataStream));

        // consume first FCGI_PARAMS request
        $record = FrameParser::parseFrame($dataStream);
        $this->assertInstanceOf(Params::class, $record);

        // consume second FCGI_PARAMS request
        $record = FrameParser::parseFrame($dataStream);
        $this->assertInstanceOf(Params::class, $record);

        $this->assertEquals(0, strlen($dataStream));
    }
}
