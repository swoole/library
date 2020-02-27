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

use PHPUnit\Framework\TestCase;
use Swoole\FastCGI;

/**
 * @internal
 * @coversNothing
 */
class RecordTest extends TestCase
{
    // from the wireshark captured traffic
    public static $rawRequest = '01010001000800000001010000000000';

    public function testUnpackingPacket(): void
    {
        $packet = hex2bin(self::$rawRequest);
        $record = Record::unpack($packet);

        // Verify all general fields
        $this->assertEquals($record->getVersion(), FastCGI::VERSION_1);
        $this->assertEquals($record->getType(), FastCGI::BEGIN_REQUEST);
        $this->assertEquals($record->getRequestId(), 1);
        $this->assertEquals($record->getContentLength(), 8);
        $this->assertEquals($record->getPaddingLength(), 0);

        // Check payload data
        $this->assertEquals($record->getContentData(), hex2bin('0001010000000000'));
    }

    public function testPackingPacket(): void
    {
        $record = new Record();
        $record->setRequestId(5);
        $record->setContentData('12345');
        $packet = (string) $record;

        $this->assertEquals($packet, hex2bin('010b0005000503003132333435000000'));
        $result = Record::unpack($packet);
        $this->assertEquals($result->getType(), FastCGI::UNKNOWN_TYPE);
        $this->assertEquals($result->getRequestId(), 5);
        $this->assertEquals($result->getContentData(), '12345');
    }

    /**
     * Padding size should resize the packet size to the 8 bytes boundary for optimal performance
     */
    public function testAutomaticCalculationOfPaddingLength(): void
    {
        $record = new Record();
        $record->setContentData('12345');
        $this->assertEquals($record->getPaddingLength(), 3);

        $record->setContentData('12345678');
        $this->assertEquals($record->getPaddingLength(), 0);
    }
}
