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

use Swoole\FastCGI;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\FastCGI\Record
 */
class RecordTest extends TestCase
{
    // from the wireshark captured traffic
    public static string $rawRequest = '01010001000800000001010000000000';

    public function testUpdatedPayloadMatchesTheSerializedLength(): void
    {
        $record = new Record\Params(['a' => 'b']);
        $record->setContentData('xyz');
        $packet = $record->toString();
        self::assertSame(16, strlen($packet));
        self::assertSame('xyz', Record::unpack($packet)->getContentData());
        self::assertSame(5, Record::unpack($packet)->getPaddingLength());
        self::assertSame($record->__toString(), $packet);
    }

    public function testMaximumContentLengthAndOverflow(): void
    {
        $record  = new Record();
        $content = str_repeat('x', FastCGI::MAX_CONTENT_LENGTH);
        $record->setContentData($content);
        $decoded = Record::unpack((string) $record);
        self::assertSame($content, $decoded->getContentData());
        self::assertSame(1, $decoded->getPaddingLength());

        try {
            $record->setContentData($content . 'x');
            self::fail('Oversized content must be rejected.');
        } catch (\LengthException $e) {
            self::assertSame($content, $record->getContentData(), 'A failed update leaves the record intact.');
            self::assertSame(FastCGI::MAX_CONTENT_LENGTH, $record->getContentLength());
        }
    }

    public function testUnpackingPacket(): void
    {
        /** @var string $packet */
        $packet = hex2bin(self::$rawRequest);
        $record = Record::unpack($packet);

        // Verify all general fields
        $this->assertEquals(FastCGI::VERSION_1, $record->getVersion());
        $this->assertEquals(FastCGI::BEGIN_REQUEST, $record->getType());
        $this->assertEquals(1, $record->getRequestId());
        $this->assertEquals(8, $record->getContentLength());
        $this->assertEquals(0, $record->getPaddingLength());

        // Check payload data
        $this->assertEquals(hex2bin('0001010000000000'), $record->getContentData());
    }

    public function testPackingPacket(): void
    {
        $record = new Record();
        $record->setRequestId(5);
        $record->setContentData('12345');
        $packet = (string) $record;

        $this->assertEquals($packet, hex2bin('010b0005000503003132333435000000'));
        $result = Record::unpack($packet);
        $this->assertEquals(FastCGI::UNKNOWN_TYPE, $result->getType());
        $this->assertEquals(5, $result->getRequestId());
        $this->assertEquals('12345', $result->getContentData());
    }

    /**
     * Padding size should resize the packet size to the 8 bytes boundary for optimal performance
     */
    public function testAutomaticCalculationOfPaddingLength(): void
    {
        $record = new Record();
        $record->setContentData('12345');
        $this->assertEquals(3, $record->getPaddingLength());

        $record->setContentData('12345678');
        $this->assertEquals(0, $record->getPaddingLength());
    }
}
