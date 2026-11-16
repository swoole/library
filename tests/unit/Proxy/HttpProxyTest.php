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
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\Server;
use Swoole\Coroutine\Server as CoroutineServer;
use Swoole\Coroutine\Server\Connection as CoroutineConnection;
use Swoole\Coroutine\WaitGroup;
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

    public function testPooledConnectionsAreReused(): void
    {
        self::coRun(function (): void {
            [$backend, $stats] = self::startBackend();
            $front             = new Server('127.0.0.1', 0);
            (new HttpProxy('127.0.0.1', $backend->port, false, 2.0, maxConnections: 1))->attach($front);
            go(static fn () => $front->start());
            try {
                for ($i = 0; $i < 2; $i++) {
                    $client = new Client('127.0.0.1', $front->port);
                    $client->set(['timeout' => 2]);
                    $this->assertTrue($client->get('/'));
                    $this->assertSame(200, $client->statusCode);
                    $this->assertSame('ok', $client->body);
                    $client->close();
                    // Let the proxy return the connection to the pool before the next request.
                    self::pause(0.01);
                }
                $this->assertSame(1, $stats->connections);
                $this->assertSame(2, $stats->requests);
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testMaxConnectionsBoundsConcurrentBackendConnections(): void
    {
        self::coRun(function (): void {
            [$backend, $stats] = self::startBackend(0.05);
            $front             = new Server('127.0.0.1', 0);
            (new HttpProxy('127.0.0.1', $backend->port, false, 2.0, maxConnections: 1))->attach($front);
            go(static fn () => $front->start());
            try {
                $statusCodes = [];
                $waitGroup   = new WaitGroup();
                for ($i = 0; $i < 2; $i++) {
                    $waitGroup->add();
                    go(static function () use ($front, $waitGroup, &$statusCodes): void {
                        $client = new Client('127.0.0.1', $front->port);
                        $client->set(['timeout' => 2]);
                        $client->get('/');
                        $statusCodes[] = $client->statusCode;
                        $client->close();
                        $waitGroup->done();
                    });
                }
                $waitGroup->wait();
                sort($statusCodes);
                $this->assertSame([200, 200], $statusCodes);
                $this->assertSame(1, $stats->connections);
                $this->assertSame(2, $stats->requests);
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testPoolCanBeDisabled(): void
    {
        self::coRun(function (): void {
            [$backend, $stats] = self::startBackend();
            $front             = new Server('127.0.0.1', 0);
            (new HttpProxy('127.0.0.1', $backend->port, false, 2.0, maxConnections: -1))->attach($front);
            go(static fn () => $front->start());
            try {
                for ($i = 0; $i < 2; $i++) {
                    $client = new Client('127.0.0.1', $front->port);
                    $client->set(['timeout' => 2]);
                    $this->assertTrue($client->get('/'));
                    $this->assertSame(200, $client->statusCode);
                    $client->close();
                }
                $this->assertSame(2, $stats->connections);
                $this->assertSame(2, $stats->requests);
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testIdleConnectionsAreRecycled(): void
    {
        self::coRun(function (): void {
            [$backend, $stats] = self::startBackend();
            $front             = new Server('127.0.0.1', 0);
            (new HttpProxy('127.0.0.1', $backend->port, false, 2.0, maxConnections: 1, maxIdleTime: 0.05))->attach($front);
            go(static fn () => $front->start());
            try {
                $client = new Client('127.0.0.1', $front->port);
                $client->set(['timeout' => 2]);
                $this->assertTrue($client->get('/'));
                $this->assertSame(200, $client->statusCode);
                $client->close();

                self::pause(0.1);

                $client = new Client('127.0.0.1', $front->port);
                $client->set(['timeout' => 2]);
                $this->assertTrue($client->get('/'));
                $this->assertSame(200, $client->statusCode);
                $client->close();

                $this->assertSame(2, $stats->connections);
                $this->assertSame(2, $stats->requests);
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testStalePooledConnectionIsReplaced(): void
    {
        self::coRun(function (): void {
            [$backend, $stats] = self::startBackend(0.0, true);
            $front             = new Server('127.0.0.1', 0);
            (new HttpProxy('127.0.0.1', $backend->port, false, 2.0, maxConnections: 1, maxIdleTime: 0.0))->attach($front);
            go(static fn () => $front->start());
            try {
                for ($i = 0; $i < 2; $i++) {
                    $client = new Client('127.0.0.1', $front->port);
                    $client->set(['timeout' => 2]);
                    $this->assertTrue($client->get('/'));
                    $this->assertSame(200, $client->statusCode);
                    $this->assertSame('ok', $client->body);
                    $client->close();
                    self::pause(0.02);
                }
                $this->assertSame(2, $stats->connections);
                $this->assertSame(2, $stats->requests);
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testPoolExhaustionIsABadGateway(): void
    {
        self::coRun(function (): void {
            [$backend, $stats] = self::startBackend(0.3);
            $front             = new Server('127.0.0.1', 0);
            (new HttpProxy('127.0.0.1', $backend->port, false, 2.0, maxConnections: 1, poolWaitTimeout: 0.05))->attach($front);
            go(static fn () => $front->start());
            try {
                $firstStatusCode = null;
                $waitGroup       = new WaitGroup();
                $waitGroup->add();
                go(static function () use ($front, $waitGroup, &$firstStatusCode): void {
                    $client = new Client('127.0.0.1', $front->port);
                    $client->set(['timeout' => 2]);
                    $client->get('/');
                    $firstStatusCode = $client->statusCode;
                    $client->close();
                    $waitGroup->done();
                });
                for ($i = 0; $i < 1000 && $stats->requests === 0; $i++) {
                    self::pause(0.001);
                }
                $this->assertSame(1, $stats->requests);

                $client = new Client('127.0.0.1', $front->port);
                $client->set(['timeout' => 2]);
                $this->assertTrue($client->get('/'));
                $this->assertSame(502, $client->statusCode);
                $this->assertSame('Bad Gateway', $client->body);
                $client->close();

                $waitGroup->wait();
                $this->assertSame(200, $firstStatusCode);
                $this->assertSame(1, $stats->connections);
                $this->assertSame(1, $stats->requests);
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testAStalePooledConnectionIsRetriedForAnIdempotentMethod(): void
    {
        self::coRun(function (): void {
            [$backend, $stats] = self::startBackend(0.0, false, true);
            $front             = new Server('127.0.0.1', 0);
            (new HttpProxy('127.0.0.1', $backend->port, false, 2.0, maxConnections: 1, maxIdleTime: 0.0))->attach($front);
            go(static fn () => $front->start());
            try {
                for ($i = 0; $i < 2; $i++) {
                    $client = new Client('127.0.0.1', $front->port);
                    $client->set(['timeout' => 2]);
                    $this->assertTrue($client->get('/'));
                    $this->assertSame(200, $client->statusCode);
                    $this->assertSame('ok', $client->body);
                    $client->close();
                    self::pause(0.01);
                }
                // The second request met a closed connection and ran again over a new one.
                $this->assertSame(2, $stats->connections);
                $this->assertSame(3, $stats->requests);
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testAStalePooledConnectionIsNotRetriedForAPost(): void
    {
        self::coRun(function (): void {
            [$backend, $stats] = self::startBackend(0.0, false, true);
            $front             = new Server('127.0.0.1', 0);
            (new HttpProxy('127.0.0.1', $backend->port, false, 2.0, maxConnections: 1, maxIdleTime: 0.0))->attach($front);
            go(static fn () => $front->start());
            try {
                $client = new Client('127.0.0.1', $front->port);
                $client->set(['timeout' => 2]);
                $this->assertTrue($client->get('/'));
                $this->assertSame(200, $client->statusCode);
                $client->close();
                self::pause(0.01);

                $client = new Client('127.0.0.1', $front->port);
                $client->set(['timeout' => 2]);
                $client->setMethod('POST');
                $this->assertTrue($client->execute('/'));
                $this->assertSame(502, $client->statusCode);
                $client->close();

                $this->assertSame(1, $stats->connections);
                $this->assertSame(2, $stats->requests);
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testReleasedConnectionsDoNotKeepTheLastPayload(): void
    {
        self::coRun(function (): void {
            $seenBody = null;
            $backend  = new Server('127.0.0.1', 0);
            $backend->handle('/', function (Request $request, Response $response) use (&$seenBody): void {
                $seenBody = $request->getContent();
                $response->header('x-backend', 'ok');
                $response->end(str_repeat('b', 1024));
            });
            $front = new Server('127.0.0.1', 0);
            $proxy = new HttpProxy('127.0.0.1', $backend->port, false, 2.0, maxConnections: 1);
            $proxy->attach($front);
            go(static fn () => $backend->start());
            go(static fn () => $front->start());
            try {
                $client = new Client('127.0.0.1', $front->port);
                $client->set(['timeout' => 2]);
                $client->setMethod('POST');
                $client->setData(str_repeat('a', 1024));
                $this->assertTrue($client->execute('/'));
                $this->assertSame(200, $client->statusCode);
                $client->close();
                self::pause(0.01);
                $this->assertSame(str_repeat('a', 1024), $seenBody);

                $pool = (new \ReflectionProperty(HttpProxy::class, 'pool'))->getValue($proxy);
                $this->assertInstanceOf(Channel::class, $pool);
                $idle = $pool->pop(0.001);
                $this->assertInstanceOf(Client::class, $idle);
                $this->assertSame('', $idle->body);
                $this->assertNull($idle->requestBody);
                $this->assertSame([], $idle->requestHeaders);
                $idle->close();
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testAClientDoesNotReuseThePreviousCallersCookies(): void
    {
        self::coRun(function (): void {
            $seenCookies = [];
            $backend     = new Server('127.0.0.1', 0);
            $backend->handle('/', function (Request $request, Response $response) use (&$seenCookies): void {
                $seenCookies[] = $request->cookie ?? null;
                if (($request->server['request_uri'] ?? '') === '/login') {
                    $response->header('set-cookie', 'session=alice; Path=/; HttpOnly');
                }
                $response->end('ok');
            });
            $front = new Server('127.0.0.1', 0);
            (new HttpProxy('127.0.0.1', $backend->port, false, 2.0, maxConnections: 1))->attach($front);
            go(static fn () => $backend->start());
            go(static fn () => $front->start());
            try {
                $client = new Client('127.0.0.1', $front->port);
                $client->set(['timeout' => 2]);
                $this->assertTrue($client->get('/login'));
                $this->assertSame(200, $client->statusCode);
                $client->close();
                self::pause(0.01);

                // A separate frontend client sends no cookies; the backend must not see the
                // session cookie that the pooled backend client picked up from the first one.
                $client = new Client('127.0.0.1', $front->port);
                $client->set(['timeout' => 2]);
                $this->assertTrue($client->get('/whoami'));
                $this->assertSame(200, $client->statusCode);
                $client->close();

                $this->assertSame([null, null], $seenCookies);
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testAConnectionCloseWakesAPoolWaiter(): void
    {
        self::coRun(function (): void {
            $firstStarted = new Channel(1);
            $releaseFirst = new Channel(1);
            $served       = 0;
            $backend      = new Server('127.0.0.1', 0);
            $backend->handle('/', function (Request $request, Response $response) use (&$served, $firstStarted, $releaseFirst): void {
                $served++;
                if (($request->server['request_uri'] ?? '') === '/first') {
                    $firstStarted->push(true);
                    $releaseFirst->pop(2.0);
                    $response->header('connection', 'close');
                    $response->end('first');

                    return;
                }
                $response->end('second');
            });
            $front = new Server('127.0.0.1', 0);
            $proxy = new HttpProxy('127.0.0.1', $backend->port, false, 2.0, maxConnections: 1, poolWaitTimeout: 1.0);
            $proxy->attach($front);
            go(static fn () => $backend->start());
            go(static fn () => $front->start());
            try {
                $statuses  = [];
                $waitGroup = new WaitGroup();

                $waitGroup->add();
                go(static function () use ($front, $waitGroup, &$statuses, $firstStarted, $releaseFirst): void {
                    $client = new Client('127.0.0.1', $front->port);
                    $client->set(['timeout' => 3]);
                    $client->get('/first');
                    $statuses['first'] = $client->statusCode;
                    $client->close();
                    $waitGroup->done();
                });
                $this->assertTrue($firstStarted->pop(2.0));

                $waitGroup->add();
                go(static function () use ($front, $waitGroup, &$statuses): void {
                    $client = new Client('127.0.0.1', $front->port);
                    $client->set(['timeout' => 3]);
                    $client->get('/second');
                    $statuses['second'] = $client->statusCode;
                    $client->close();
                    $waitGroup->done();
                });
                $pool = (new \ReflectionProperty(HttpProxy::class, 'pool'))->getValue($proxy);
                for ($i = 0; $i < 500 && ($pool->stats()['consumer_num'] ?? 0) === 0; $i++) {
                    self::pause(0.001);
                }
                $this->assertGreaterThan(0, $pool->stats()['consumer_num'] ?? 0);
                $releaseFirst->push(true);

                $waitGroup->wait();
                $this->assertSame(200, $statuses['first']);
                $this->assertSame(200, $statuses['second']);
                $this->assertSame(2, $served);
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    public function testAFailedConnectionWakesAPoolWaiter(): void
    {
        self::coRun(function (): void {
            $firstReceived = new Channel(1);
            $closeFirst    = new Channel(1);
            $connections   = 0;
            $backend       = new CoroutineServer('127.0.0.1', 0);
            $backend->handle(static function (CoroutineConnection $socket) use (&$connections, $firstReceived, $closeFirst): void {
                $connections++;
                $request = '';
                while (!str_contains($request, "\r\n\r\n")) {
                    $chunk = $socket->recv(1);
                    if (!is_string($chunk) || $chunk === '') {
                        return;
                    }
                    $request .= $chunk;
                }
                if ($connections === 1) {
                    $firstReceived->push(true);
                    $closeFirst->pop(2.0);
                    $socket->close();

                    return;
                }
                $socket->send("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok");
            });
            $front = new Server('127.0.0.1', 0);
            $proxy = new HttpProxy('127.0.0.1', $backend->port, false, 2.0, maxConnections: 1, poolWaitTimeout: 1.0);
            $proxy->attach($front);
            go(static fn () => $backend->start());
            go(static fn () => $front->start());
            try {
                $statuses  = [];
                $waitGroup = new WaitGroup();

                $waitGroup->add();
                go(static function () use ($front, $waitGroup, &$statuses): void {
                    $client = new Client('127.0.0.1', $front->port);
                    $client->set(['timeout' => 3]);
                    $client->get('/first');
                    $statuses['first'] = $client->statusCode;
                    $client->close();
                    $waitGroup->done();
                });
                $this->assertTrue($firstReceived->pop(2.0));

                $waitGroup->add();
                go(static function () use ($front, $waitGroup, &$statuses): void {
                    $client = new Client('127.0.0.1', $front->port);
                    $client->set(['timeout' => 3]);
                    $client->get('/second');
                    $statuses['second'] = $client->statusCode;
                    $client->close();
                    $waitGroup->done();
                });
                $pool = (new \ReflectionProperty(HttpProxy::class, 'pool'))->getValue($proxy);
                for ($i = 0; $i < 500 && ($pool->stats()['consumer_num'] ?? 0) === 0; $i++) {
                    self::pause(0.001);
                }
                $this->assertGreaterThan(0, $pool->stats()['consumer_num'] ?? 0);
                $closeFirst->push(true);

                $waitGroup->wait();
                $this->assertSame(502, $statuses['first']);
                $this->assertSame(200, $statuses['second']);
                $this->assertSame(2, $connections);
            } finally {
                $front->shutdown();
                $backend->shutdown();
            }
        });
    }

    /**
     * Yields the current coroutine for the given number of seconds.
     */
    private static function pause(float $seconds): void
    {
        // A pop on a channel with no producer returns when its timeout runs out.
        (new Channel(1))->pop($seconds);
    }

    /**
     * A minimal HTTP backend that counts the connections and requests it serves. It keeps a
     * connection open across requests unless $closeAfterResponse or $closeOnSecondRequest is set.
     *
     * @return array{0: CoroutineServer, 1: \stdClass}
     */
    private static function startBackend(float $delay = 0.0, bool $closeAfterResponse = false, bool $closeOnSecondRequest = false): array
    {
        $stats              = new \stdClass();
        $stats->connections = 0;
        $stats->requests    = 0;
        $server             = new CoroutineServer('127.0.0.1', 0);
        $server->handle(static function (CoroutineConnection $socket) use ($stats, $delay, $closeAfterResponse, $closeOnSecondRequest): void {
            $stats->connections++;
            $connectionRequests = 0;
            $buffer             = '';
            while (true) {
                $chunk = $socket->recv(1);
                if (!is_string($chunk) || $chunk === '') {
                    return;
                }
                $buffer .= $chunk;
                while (($end = strpos($buffer, "\r\n\r\n")) !== false) {
                    $buffer = substr($buffer, $end + 4);
                    $stats->requests++;
                    $connectionRequests++;
                    if ($closeOnSecondRequest && $connectionRequests > 1) {
                        $socket->close();

                        return;
                    }
                    if ($delay > 0) {
                        self::pause($delay);
                    }
                    $socket->send("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok");
                    if ($closeAfterResponse) {
                        $socket->close();

                        return;
                    }
                }
            }
        });
        go(static fn () => $server->start());

        return [$server, $stats];
    }
}
