<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\FastCGI\Record;

use PHPUnit\Framework\TestCase;
use Swoole\FastCGI;

/**
 * @internal
 * @coversNothing
 */
class UnknownTypeTest extends TestCase
{
    protected static $rawMessage = '010b0001000800002a57544621000000';

    public function testPacking(): void
    {
        $request = new UnknownType(42, 'WTF!');
        $this->assertEquals(FastCGI::UNKNOWN_TYPE, $request->getType());
        $this->assertEquals(42, $request->getUnrecognizedType());

        $this->assertSame(self::$rawMessage, bin2hex((string) $request));
    }

    public function testUnpacking(): void
    {
        $request = UnknownType::unpack(hex2bin(self::$rawMessage));

        $this->assertEquals(FastCGI::UNKNOWN_TYPE, $request->getType());
        $this->assertEquals(42, $request->getUnrecognizedType());
    }
}
