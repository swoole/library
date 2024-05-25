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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swoole\FastCGI\Record\BeginRequest;
use Swoole\FastCGI\Record\Params;

/**
 * @internal
 */
#[CoversClass(FrameParser::class)]
class FrameParserTest extends TestCase
{
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
