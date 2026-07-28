<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine\Http2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Coroutine\WaitGroup;
use Swoole\Http2\Request;
use Swoole\Http2\Response;

use function Swoole\Coroutine\go;
use function Swoole\Coroutine\run;

/**
 * @internal
 */
#[CoversClass(MultiplexClient::class)]
class MultiplexClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(Client::class, false)) {
            self::markTestSkipped('Swoole is compiled without HTTP/2 support (--enable-http2).');
        }
    }

    public function testRequest(): void
    {
        run(function () {
            $server = $this->startServer();
            $client = new MultiplexClient('127.0.0.1', $server->port);
            $client->set(['timeout' => 5]);
            $this->assertTrue($client->connect());

            $wg = new WaitGroup();
            for ($i = 1; $i <= 30; $i++) {
                $wg->add();
                go(function () use ($client, $wg, $i) {
                    try {
                        $response = $client->request(self::newRequest('/', "payload-{$i}"), 10);
                        $this->assertInstanceOf(Response::class, $response);
                        $this->assertSame(200, $response->statusCode);
                        $this->assertSame("payload-{$i}", $response->data);
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();

            $client->close();
            $server->shutdown();
        });
    }

    public function testRequestTimeout(): void
    {
        run(function () {
            $server = $this->startServer();
            $client = new MultiplexClient('127.0.0.1', $server->port);
            $client->set(['timeout' => 5]);
            $this->assertTrue($client->connect());

            $start = microtime(true);
            $this->assertFalse($client->request(self::newRequest('/slow', 'late'), 0.2));
            $this->assertLessThan(0.45, microtime(true) - $start);

            // While this second request is in flight, the late response of the timed-out stream
            // arrives and must be dropped — not delivered anywhere. The second request uses no
            // explicit timeout, exercising the fallback to the client's `timeout` setting.
            $response = $client->request(self::newRequest('/slow', 'on-time'));
            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame('on-time', $response->data);

            $client->close();
            $server->shutdown();
        });
    }

    public function testReconnectAfterServerClosesConnection(): void
    {
        run(function () {
            $server = $this->startServer();
            $client = new MultiplexClient('127.0.0.1', $server->port);
            $client->set(['timeout' => 5]);
            $this->assertTrue($client->connect());

            // The server closes the connection without answering the stream: the pending request
            // fails, and the recv loop tears the client down.
            $this->assertFalse($client->request(self::newRequest('/bye', 'bye'), 2));
            Coroutine::sleep(0.1);
            $this->assertFalse($client->connected);

            // The next request reconnects transparently. The new connection reuses the aborted
            // stream's ID, so this also verifies that the aborted stream left no marker behind
            // that would drop the new stream's response.
            $response = $client->request(self::newRequest('/', 'back'));
            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame('back', $response->data);

            $client->close();
            $server->shutdown();
        });
    }

    public function testCloseWithRequestInFlight(): void
    {
        run(function () {
            $server = $this->startServer();
            $client = new MultiplexClient('127.0.0.1', $server->port);
            $client->set(['timeout' => 5]);
            $this->assertTrue($client->connect());

            $wg     = new WaitGroup();
            $result = null;
            $wg->add();
            go(function () use ($client, $wg, &$result) {
                try {
                    $result = $client->request(self::newRequest('/slow', 'in-flight'));
                } finally {
                    $wg->done();
                }
            });
            Coroutine::sleep(0.1); // let the request get in flight

            $start = microtime(true);
            $client->close();
            $wg->wait();
            $this->assertFalse($result);
            $this->assertLessThan(0.3, microtime(true) - $start); // woken by close(), not by a timeout

            // As above: the reused stream ID of the new connection must work.
            $response = $client->request(self::newRequest('/', 'again'));
            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame('again', $response->data);

            $client->close();
            $server->shutdown();
        });
    }

    public function testIdleClose(): void
    {
        run(function () {
            $server = $this->startServer();

            $keeper = new MultiplexClient('127.0.0.1', $server->port);
            $keeper->set(['timeout' => 5, 'heartbeat_idle_time' => 0]); // idle closing disabled
            $closer = new MultiplexClient('127.0.0.1', $server->port);
            $closer->set(['timeout' => 5, 'heartbeat_check_interval' => 0.1, 'heartbeat_idle_time' => 0.5]);

            foreach ([$keeper, $closer] as $client) {
                $this->assertTrue($client->connect());
                $this->assertInstanceOf(Response::class, $client->request(self::newRequest('/', 'ping')));
            }

            // The idle check compares whole seconds, so the 0.5 s idle time triggers within ~2 s.
            Coroutine::sleep(2.5);
            $this->assertFalse($closer->connected);
            $this->assertTrue($keeper->connected);

            // An idle-closed client reconnects transparently on the next request.
            $response = $closer->request(self::newRequest('/', 'wake'));
            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame('wake', $response->data);

            $keeper->close();
            $closer->close();
            $server->shutdown();
        });
    }

    public function testRequestAgainstUnreachableServer(): void
    {
        run(function () {
            // Reserve a port with a throwaway server, then shut it down so nothing listens on it.
            $server = $this->startServer();
            $port   = $server->port;
            $server->shutdown();
            Coroutine::sleep(0.05);

            $client = new MultiplexClient('127.0.0.1', $port);
            $client->set(['timeout' => 0.5]);
            $this->assertFalse($client->request(self::newRequest('/', 'nobody-home')));
            $client->close();
        });
    }

    private function startServer(): Server
    {
        $server = new Server('127.0.0.1', 0);
        $server->set(['open_http2_protocol' => true]);
        $server->handle('/slow', function ($request, $response) {
            Coroutine::sleep(0.5);
            $response->end($request->getContent());
        });
        $server->handle('/bye', function ($request, $response) {
            $response->close();
        });
        $server->handle('/', function ($request, $response) {
            $response->end($request->getContent());
        });
        go(function () use ($server) {
            $server->start();
        });
        return $server;
    }

    private static function newRequest(string $path, string $data): Request
    {
        $request         = new Request();
        $request->method = 'POST';
        $request->path   = $path;
        $request->data   = $data;
        return $request;
    }
}
