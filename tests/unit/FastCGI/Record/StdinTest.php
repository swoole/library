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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swoole\FastCGI;

/**
 * @internal
 */
#[CoversClass(Stdin::class)]
class StdinTest extends TestCase
{
    protected static string $rawMessage = '01050001000404007465737400000000';

    public function testPacking(): void
    {
        $request = new Stdin('test');
        $this->assertEquals('test', $request->getContentData());
        $this->assertEquals(FastCGI::STDIN, $request->getType());
        $this->assertSame(self::$rawMessage, bin2hex((string) $request));
    }

    public function testUnpacking(): void
    {
        /** @var string $binaryData */
        $binaryData = hex2bin(self::$rawMessage);
        $request    = Stdin::unpack($binaryData);
        $this->assertEquals(FastCGI::STDIN, $request->getType());
        $this->assertEquals('test', $request->getContentData());
    }
}
