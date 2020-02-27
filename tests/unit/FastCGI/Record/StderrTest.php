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
class StderrTest extends TestCase
{
    protected static $rawMessage = '01070001000404007465737400000000';

    public function testPacking(): void
    {
        $request = new Stderr('test');
        $this->assertEquals($request->getContentData(), 'test');
        $this->assertEquals($request->getType(), FastCGI::STDERR);
        $this->assertSame(self::$rawMessage, bin2hex((string) $request));
    }

    public function testUnpacking(): void
    {
        $request = Stderr::unpack(hex2bin(self::$rawMessage));
        $this->assertEquals($request->getType(), FastCGI::STDERR);
        $this->assertEquals($request->getContentData(), 'test');
    }
}
