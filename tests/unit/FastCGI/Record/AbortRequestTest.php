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
#[CoversClass(AbortRequest::class)]
class AbortRequestTest extends TestCase
{
    protected static string $rawMessage = '0102000100000000';

    public function testPacking(): void
    {
        $request = new AbortRequest(1);
        $this->assertEquals(FastCGI::ABORT_REQUEST, $request->getType());
        $this->assertEquals(1, $request->getRequestId());

        $this->assertSame(self::$rawMessage, bin2hex((string) $request));
    }

    public function testUnpacking(): void
    {
        /** @var string $binaryData */
        $binaryData = hex2bin(self::$rawMessage);
        $request    = AbortRequest::unpack($binaryData);
        $this->assertEquals(FastCGI::ABORT_REQUEST, $request->getType());
        $this->assertEquals(1, $request->getRequestId());
    }
}
