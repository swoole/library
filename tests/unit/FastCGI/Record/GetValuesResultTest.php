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
class GetValuesResultTest extends TestCase
{
    protected static string $rawMessage = '010a0001001206000f01464347495f4d5058535f434f4e4e5331000000000000';

    public function testPacking(): void
    {
        $request = new GetValuesResult(['FCGI_MPXS_CONNS' => '1']);
        $this->assertEquals(FastCGI::GET_VALUES_RESULT, $request->getType());
        $this->assertEquals(['FCGI_MPXS_CONNS' => '1'], $request->getValues());

        $this->assertSame(self::$rawMessage, bin2hex((string) $request));
    }

    public function testUnpacking(): void
    {
        /** @var string $binaryData */
        $binaryData = hex2bin(self::$rawMessage);
        $request    = GetValuesResult::unpack($binaryData);

        $this->assertEquals(FastCGI::GET_VALUES_RESULT, $request->getType());
        $this->assertEquals(['FCGI_MPXS_CONNS' => '1'], $request->getValues());
    }
}
