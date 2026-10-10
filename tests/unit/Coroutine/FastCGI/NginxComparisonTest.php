<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine\FastCGI;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\Coroutine\Http\Server;
use Swoole\Tests\TestCase;

/**
 * Same HTTP inputs, same PHP-FPM pool and fixtures, two different FastCGI front ends.
 * See tests/fastcgi-nginx.md for the optional nginx service configuration.
 *
 * @internal
 * @covers \Swoole\Coroutine\FastCGI\Client
 * @covers \Swoole\Coroutine\FastCGI\Proxy
 */
class NginxComparisonTest extends TestCase
{
    /** @dataProvider requests */
    public function testMatchesNginx(string $method, string $path, array $headers, string $body, int $status, ?int $swooleStatus = null): void
    {
        $url = getenv('SWOOLE_FASTCGI_NGINX_URL');
        if (!$url) {
            self::markTestSkipped('Set SWOOLE_FASTCGI_NGINX_URL to enable the nginx + PHP-FPM comparison.');
        }
        $nginx = parse_url($url);
        self::coRun(function () use ($nginx, $method, $path, $headers, $body, $status, $swooleStatus): void {
            $proxy = (new Proxy(getenv('SWOOLE_FASTCGI_FPM_URL') ?: 'tcp://php-fpm:9000',
                getenv('SWOOLE_FASTCGI_DOCUMENT_ROOT') ?: DOCUMENT_ROOT . '/fastcgi'))->withTimeout(0.6);
            $server = new Server('127.0.0.1', 0, false);
            $server->set(['package_max_length' => 32 * 1024 * 1024]);
            $server->handle('/', static function ($request, $response) use ($proxy): void {
                try {
                    $proxy->pass($request, $response);
                } catch (Client\Exception $e) {
                    $response->status($e->getCode() === SOCKET_ETIMEDOUT ? 504 : 502);
                    $response->end();
                } catch (\LengthException $e) {
                    $response->status(500);
                    $response->end();
                }
            });
            Coroutine::create(static fn () => $server->start());
            try {
                $expected = self::request($nginx['host'], $nginx['port'] ?? 80, $method, $path, $headers, $body);
                $actual   = self::request('127.0.0.1', $server->port, $method, $path, $headers, $body);
                self::assertSame($status, $expected['status']);
                self::assertSame($swooleStatus ?? $status, $actual['status']);
                // nginx's generated gateway error pages are outside the FastCGI response being compared.
                if ($status < 500 && $swooleStatus === null) {
                    self::assertSame($expected, $actual);
                }
                if ($swooleStatus !== null) {
                    self::assertStringNotContainsString('FASTCGI_SIBLING_MARKER', $actual['body']);
                }
            } finally {
                $server->shutdown();
            }
        });
    }

    public static function requests(): array
    {
        return [
            'GET'                             => ['GET', '/inspect.php', [], '', 200],
            'default index'                   => ['GET', '/?query=1', [], '', 200],
            'zero query'                      => ['GET', '/inspect.php?0', [], '', 200],
            'encoded Cookie values'           => ['GET', '/inspect.php', ['Cookie' => 'session=a%2Bb%20c; zero=0; semi=%3B'], '', 200],
            'empty body'                      => ['POST', '/inspect.php', [], '', 200],
            'zero body'                       => ['POST', '/inspect.php', [], '0', 200],
            'binary body'                     => ['POST', '/inspect.php', [], "a\0\xffb", 200],
            'form body'                       => ['POST', '/inspect.php', ['Content-Type' => 'application/x-www-form-urlencoded'], 'a=1&b=two', 200],
            '65535 bytes'                     => ['POST', '/inspect.php', [], str_repeat('x', 65535), 200],
            '65536 bytes'                     => ['POST', '/inspect.php', [], str_repeat('x', 65536), 200],
            'two MiB'                         => ['POST', '/inspect.php', [], str_repeat('x', 2 * 1024 * 1024), 200],
            'HEAD'                            => ['HEAD', '/inspect.php', [], '', 200],
            'repeated headers'                => ['GET', '/headers.php', [], '', 200],
            'PHP error status'                => ['GET', '/status.php?code=400', [], '', 400],
            'missing script'                  => ['GET', '/missing.php', [], '', 404],
            'stream exceeds timeout in total' => ['GET', '/stream.php', [], '', 200],
            'send timeout'                    => ['PUT', '/slow-read.php', [], str_repeat('x', 16 * 1024 * 1024), 504],
            'oversized PARAMS'                => ['GET', '/inspect.php', array_fill_keys(['X-A', 'X-B', 'X-C', 'X-D'], str_repeat('x', 16300)), '', 500],
            'sibling static file is rejected' => ['GET', '/../fastcgi-sibling/outside.txt', [], '', 400, 404],
        ];
    }

    private static function request(string $host, int $port, string $method, string $path, array $headers, string $body): array
    {
        $client = new HttpClient($host, $port);
        $client->set(['timeout' => 5]);
        $client->setHeaders(['Host' => 'localhost', 'Accept-Encoding' => 'identity'] + $headers);
        if ($body !== '') {
            $client->setData($body);
        }
        $client->setMethod($method);
        try {
            self::assertTrue($client->execute($path));
            $responseHeaders = $client->headers;
            foreach (['server', 'date', 'connection', 'content-length', 'transfer-encoding'] as $name) {
                unset($responseHeaders[$name]);
            }
            ksort($responseHeaders);
            return ['status' => $client->statusCode, 'headers' => $responseHeaders, 'cookies' => $client->set_cookie_headers, 'body' => $client->body];
        } finally {
            $client->close();
        }
    }
}
