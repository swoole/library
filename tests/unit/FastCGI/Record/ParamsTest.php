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

use Swoole\FastCGI;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\FastCGI\Record\Params
 */
class ParamsTest extends TestCase
{
    protected static string $rawMessage ='
        01040001005b05000f0e5343524950545f46494c454e414d452f686f6d652f746573742e7068701
        107474154455741595f494e544552464143454347492f312e310f115345525645525f534f465457
        4152455048502f50726f746f636f6c2d464347490000000000';

    /**
     * @var string[]
     */
    protected static array $params = [
        'SCRIPT_FILENAME'   => '/home/test.php',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'SERVER_SOFTWARE'   => 'PHP/Protocol-FCGI',
    ];

    public function testPacking(): void
    {
        $request = new Params(self::$params);
        $this->assertEquals(FastCGI::PARAMS, $request->getType());
        $this->assertEquals(self::$params, $request->getValues());

        $this->assertSame(preg_replace('/\s+/', '', self::$rawMessage), bin2hex((string) $request));
    }

    /**
     * The encoding of a name-value pair per the FastCGI specification: each length as one byte up to 127 and as
     * four bytes with the top bit set above that, then the name and the value. Lengths on both sides of that
     * boundary, plus a 40 KB value, come out byte for byte the same; the whole stays within the 64 KB a record
     * can carry.
     */
    public function testPackingAroundTheLengthBoundaries(): void
    {
        $lengths = [0, 1, 127, 128, 255, 256, 1000];
        $params  = [];
        foreach ($lengths as $nameLength) {
            if ($nameLength === 0) {
                continue; // A parameter needs a name.
            }
            foreach ($lengths as $i => $valueLength) {
                // Names of exactly $nameLength bytes, distinct within one length by their last character (a letter,
                // since a numeric string would become an integer key).
                $params[str_pad(chr(ord('a') + $i), $nameLength, 'n', STR_PAD_LEFT)] = str_repeat('v', $valueLength);
            }
        }
        $params['long'] = str_repeat('x', 40000);

        $expected = '';
        foreach ($params as $name => $value) {
            $expected .= self::encodeLength(strlen($name)) . self::encodeLength(strlen($value)) . $name . $value;
        }

        $record = new Params($params);
        self::assertSame(strlen($expected), $record->getContentLength());
        self::assertSame(bin2hex($expected), bin2hex($record->getContentData()));
        self::assertSame($params, Params::unpack((string) $record)->getValues(), 'The record decodes back to the same parameters.');
    }

    private static function encodeLength(int $length): string
    {
        return $length > 127 ? pack('N', $length | 0x80000000) : pack('C', $length);
    }

    public function testUnpacking(): void
    {
        $oneLineData = preg_replace('/\s+/', '', self::$rawMessage) ?? '';
        $binaryData  = hex2bin($oneLineData);
        if ($binaryData === false) {
            throw new \ValueError('Invalid binary string format');
        }
        $request = Params::unpack($binaryData);

        $this->assertEquals(FastCGI::PARAMS, $request->getType());
        $this->assertEquals(self::$params, $request->getValues());
    }
}
