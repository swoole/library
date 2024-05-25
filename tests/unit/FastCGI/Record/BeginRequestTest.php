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
#[CoversClass(BeginRequest::class)]
class BeginRequestTest extends TestCase
{
    protected static string $rawMessage = '01010001000800000001010000000000';

    public function testPacking(): void
    {
        $request = new BeginRequest(FastCGI::RESPONDER, FastCGI::KEEP_CONN);
        $this->assertEquals(FastCGI::BEGIN_REQUEST, $request->getType());
        $this->assertEquals(FastCGI::RESPONDER, $request->getRole());
        $this->assertEquals(FastCGI::KEEP_CONN, $request->getFlags());

        $this->assertSame(self::$rawMessage, bin2hex((string) $request));
    }

    public function testUnpacking(): void
    {
        /** @var string $binaryData */
        $binaryData = hex2bin(self::$rawMessage);
        $request    = BeginRequest::unpack($binaryData);

        $this->assertEquals(FastCGI::BEGIN_REQUEST, $request->getType());
        $this->assertEquals(FastCGI::RESPONDER, $request->getRole());
        $this->assertEquals(FastCGI::KEEP_CONN, $request->getFlags());
    }
}
