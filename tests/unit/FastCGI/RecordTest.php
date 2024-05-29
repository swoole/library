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
use Swoole\FastCGI;

/**
 * @internal
 */
#[CoversClass(Record::class)]
class RecordTest extends TestCase
{
    // from the wireshark captured traffic
    public static string $rawRequest = '01010001000800000001010000000000';

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
