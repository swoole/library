<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Proxy;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Proxy\HttpProxy;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Proxy\HttpProxy
 */
class HttpProxyTest extends TestCase
{
    public function testGetAndPostAreForwardedWithStatusAndHeaders(): void
    {
        self::coRun(function (): void {
            $seen    = null;
            $backend = new Server('127.0.0.1', 0);
            $backend->handle('/', function (Request $request, Response $response) use (&$seen): void {
                $seen = [
                    'method' => $request->server['request_method'] ?? null,
                    'uri'    => $request->server['request_uri'] ?? null,
                    'query'  => $request->server['query_string'] ?? null,
                    'body'   => $request->getContent(),
                    'headers'=> $request->header ?? [],
                ];
                $response->status(201);
                $response->header('x-backend', 'ok');
                $response->header('set-cookie', 'id=1; HttpOnly');
                $response->end('pong');
            });
            $front = new Server('127.0.0.1', 0);
            (new HttpProxy('127.0.0.1', $backend->port, false, 2.0))->attach($front);
            Coroutine::create(static fn () => $backend->start());
            Coroutine::create(static fn () => $front->start());
            try {
                $client = new Client('127.0.0.1', $front->port);
                $client->setHeaders(['authorization' => 'Bearer t', 'connection' => 'close, x-drop', 'x-drop' => 'no']);
                $this->assertTrue($client->get('/item?0'));
                $this->assertSame(201, $client->statusCode);
                $this->assertSame('pong', $client->body);
                $this->assertSame('ok', $client->headers['x-backend'] ?? null);
                $this->assertContains('id=1; HttpOnly', $client->set_cookie_headers ?? []);
                $this->assertIsArray($seen);
                $this->assertSame('GET', $seen['method']);
                $this->assertSame('/item', $seen['uri']);
                $this->assertSame('0', $seen['query']);
                $this->assertSame('Bearer t', $seen['headers']['authorization'] ?? null);
                $this->assertArrayNotHasKey('x-drop', $seen['headers']);

                $client->setMethod('POST');
                $client->setHeaders(['content-type' => 'text/plain']);
                $client->setData('name=ada');
                $this->assertTrue($client->execute('/submit'));
                $this->assertSame('POST', $seen['method']);
                $this->assertSame('/submit', $seen['uri']);
                $this->assertSame('name=ada', $seen['body']);
                $client->close();
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testPutIsForwardedAndTraceIsRejected(): void
    {
        self::coRun(function (): void {
            $method  = null;
            $backend = new Server('127.0.0.1', 0);
            $backend->handle('/', function (Request $request, Response $response) use (&$method): void {
                $method = $request->server['request_method'] ?? null;
                $response->end('updated');
            });
            $front = new Server('127.0.0.1', 0);
            (new HttpProxy('127.0.0.1', $backend->port, false, 2.0))->attach($front);
            Coroutine::create(static fn () => $backend->start());
            Coroutine::create(static fn () => $front->start());
            try {
                $client = new Client('127.0.0.1', $front->port);
                $client->setMethod('PUT');
                $client->setData('next');
                $this->assertTrue($client->execute('/item'));
                $this->assertSame(200, $client->statusCode);
                $this->assertSame('updated', $client->body);
                $this->assertSame('PUT', $method);

                $client->setMethod('TRACE');
                $this->assertTrue($client->execute('/item'));
                $this->assertSame(405, $client->statusCode);
                $this->assertSame('PUT', $method);
                $client->close();
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testAnUnreachableBackendIsABadGateway(): void
    {
        self::coRun(function (): void {
            $front = new Server('127.0.0.1', 0);
            (new HttpProxy('127.0.0.1', 1, false, 0.3))->attach($front);
            Coroutine::create(static fn () => $front->start());
            try {
                $client = new Client('127.0.0.1', $front->port);
                $client->set(['timeout' => 1]);
                $this->assertTrue($client->get('/'));
                $this->assertSame(502, $client->statusCode);
                $this->assertSame('Bad Gateway', $client->body);
                $client->close();
            } finally {
                $front->shutdown();
            }
        });
    }
}
