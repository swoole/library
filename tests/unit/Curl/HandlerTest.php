<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Curl;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;

/**
 * Class HandlerTest
 *
 * @internal
 * @coversNothing
 */
class HandlerTest extends TestCase
{
    /**
     * @covers \Swoole\Curl\Handler::execute()
     */
    public function testRedirect()
    {
        Runtime::enableCoroutine(SWOOLE_HOOK_CURL);
        Coroutine\run(function () {
            $ch = curl_init('https://shorturl.at/wxWX4');
            self::assertInstanceOf(Handler::class, $ch, 'Variable $ch should be a Handler object instead of a curl resource');

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            self::assertEquals(200, $httpCode, 'HTTP status code should be 200 instead of 301');
        });
    }
}
