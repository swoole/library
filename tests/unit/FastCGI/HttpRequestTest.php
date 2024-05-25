<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\FastCGI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\FastCGI\Client;

/**
 * @internal
 */
#[CoversClass(HttpRequest::class)]
class HttpRequestTest extends TestCase
{
    /**
     * To test the Keep-Alive header when sending multiple requests to a FastCGI server.
     *
     * @see https://github.com/swoole/library/pull/169 Fix broken requests when keep-alive is turned on in the FastCGI client.
     */
    public function testKeepAlive(): void
    {
        Coroutine\run(
            function (): void {
                $client = new Client('php-fpm', 9000);

                $request = (new HttpRequest())->withScriptFilename(DOCUMENT_ROOT . '/header0.php')->withKeepConn(false);
                for ($i = 0; $i < 2; $i++) {
                    self::assertSame(200, $client->execute($request)->getStatusCode(), 'Status code should always be 200 when HTTP header keep-alive is turned off.');
                }

                $request->withKeepConn(true);
                for ($i = 0; $i < 2; $i++) {
                    self::assertSame(200, $client->execute($request)->getStatusCode(), 'Status code should always be 200 when HTTP header keep-alive is turned on.');
                }
            }
        );
    }
}
