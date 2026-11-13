<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Kafka\Protocol;

use Swoole\Kafka\Protocol\ResponseHeader\ResponseHeader;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ResponseHeaderTest extends TestCase
{
    private const TEST_CORRELATION_ID = 123;

    private const ENCODE_RESULT = '0000007b';

    private const ENCODE_RESULT_V1 = '0000007b00';

    public function testPack(): void
    {
        $header = new ResponseHeader();
        $header->setCorrelationId(self::TEST_CORRELATION_ID);
        $this->assertEquals(self::ENCODE_RESULT, bin2hex($header->pack()));
        $this->assertEquals(self::ENCODE_RESULT_V1, bin2hex($header->pack(1)));
    }

    public function testUnpack(): void
    {
        $header = new ResponseHeader();
        $header->unpack(hex2bin(self::ENCODE_RESULT), $size);
        $this->assertEquals(4, $size);
        $this->assertEquals([
            'correlationId' => self::TEST_CORRELATION_ID,
        ], $header->toArray());

        $header->unpack(hex2bin(self::ENCODE_RESULT_V1), $size, 1);
        $this->assertEquals(5, $size);
        $this->assertEquals([
            'correlationId' => self::TEST_CORRELATION_ID,
        ], $header->toArray());
    }
}
