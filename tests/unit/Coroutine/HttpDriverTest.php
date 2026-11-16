<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine;

use Swoole\Coroutine\Http\Client\Exception;
use Swoole\Coroutine\Http\ClientProxy;
use Swoole\Tests\TestCase;

use function Swoole\Coroutine\Http\request_with_http_client;
use function Swoole\Coroutine\Http\request_with_stream;

/**
 * @internal
 * @covers \Swoole\Coroutine\Http\ClientProxy
 * @covers \Swoole\Coroutine\Http\request_with_curl
 * @covers \Swoole\Coroutine\Http\request_with_http_client
 * @covers \Swoole\Coroutine\Http\request_with_stream
 */
class HttpDriverTest extends TestCase
{
    public function testStreamAcceptsZeroAndEmptyBodies(): void
    {
        self::assertSame('0', request_with_stream('data://text/plain,0', 'GET')->getBody());
        self::assertSame('', request_with_stream('data://text/plain,', 'GET')->getBody());
    }

    public function testStreamFailureThrowsAnHttpException(): void
    {
        $this->expectException(Exception::class);
        request_with_stream('data://malformed', 'GET');
    }

    public function testHttpClientRejectsAMissingHost(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('no host');
        request_with_http_client('http:foo', 'GET');
    }

    public function testFinalHeadersAndCookieValuesArePreserved(): void
    {
        $response = ClientProxy::fromHeaders('body', [
            'HTTP/1.1 302 Found', 'Location: /final', 'Set-Cookie: old=redirect',
            'HTTP/1.1 100 Continue', 'X-Interim: discarded',
            'HTTP/1.1 418 Teapot', 'X-Final: yes', 'X-Final: again',
            'Set-Cookie: session=abc=def; Path=/', 'Set-Cookie: broken', 'Set-Cookie: empty=; HttpOnly',
        ]);
        self::assertSame(418, $response->getStatusCode());
        self::assertSame(['yes', 'again'], $response->getHeaders()['x-final']);
        self::assertArrayNotHasKey('location', $response->getHeaders());
        self::assertArrayNotHasKey('x-interim', $response->getHeaders());
        self::assertSame(['session' => 'abc=def', 'empty' => ''], $response->getCookies());
    }

    /**
     * @dataProvider drivers
     */
    public function testDriversPreserveBodiesAndResponseMetadata(string $driver): void
    {
        self::coRun(function () use ($driver): void {
            $server = new Http\Server('127.0.0.1', 0);
            $server->handle('/zero', static function ($request, $response): void { $response->end('0'); });
            $server->handle('/empty', static function ($request, $response): void { $response->end(''); });
            $server->handle('/error', static function ($request, $response): void {
                $response->status(418);
                $response->header('X-Review', 'yes');
                $response->header('Set-Cookie', 'session=abc=def; Path=/');
                $response->end('teapot');
            });
            $server->handle('/echo', static function ($request, $response): void {
                $response->end($request->getContent() . '|' . ($request->header['x-test'] ?? ''));
            });
            $server->handle('/redirect', static function ($request, $response): void {
                $response->status(302);
                $response->header('Location', '/error');
                $response->end('redirect');
            });
            go(static function () use ($server): void { $server->start(); });
            $url  = 'http://127.0.0.1:' . $server->port;
            $send = __NAMESPACE__ . '\Http\request_with_' . $driver;
            try {
                self::assertSame('0', $send($url . '/zero', 'GET')->getBody());
                self::assertSame('', $send($url . '/empty', 'GET')->getBody());
                $response = $send($url . '/error', 'GET');
                self::assertSame(418, $response->getStatusCode());
                self::assertSame('teapot', $response->getBody());
                self::assertArrayHasKey('x-review', $response->getHeaders());
                self::assertSame('abc=def', $response->getCookies()['session']);
                foreach (['0', 0, []] as $data) {
                    $body = $send($url . '/echo', 'POST', $data, null, ['X-Test' => 'yes'])->getBody();
                    self::assertSame(($data === [] ? '' : '0') . '|yes', $body);
                }
                if ($driver === 'stream') {
                    $response = $send($url . '/redirect', 'GET');
                    self::assertSame(418, $response->getStatusCode());
                    self::assertSame('teapot', $response->getBody());
                    self::assertArrayNotHasKey('location', $response->getHeaders());
                }
            } finally {
                $server->shutdown();
            }
        });
    }

    public static function drivers(): iterable
    {
        yield 'http_client' => ['http_client'];
        yield 'curl' => ['curl'];
        yield 'stream' => ['stream'];
    }
}
