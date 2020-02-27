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
class StdoutTest extends TestCase
{
    protected static $rawMessage = '01060001000404007465737400000000';

    public function testPacking(): void
    {
        $request = new Stdout('test');
        $this->assertEquals($request->getContentData(), 'test');
        $this->assertEquals($request->getType(), FastCGI::STDOUT);
        $this->assertSame(self::$rawMessage, bin2hex((string) $request));
    }

    public function testUnpacking(): void
    {
        $request = Stdout::unpack(hex2bin(self::$rawMessage));
        $this->assertEquals($request->getType(), FastCGI::STDOUT);
        $this->assertEquals($request->getContentData(), 'test');
    }
}
