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
#[CoversClass(Params::class)]
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
