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

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Coroutine\WaitGroup;
use Swoole\Http2\Request;
use Swoole\Http2\Response;
use Swoole\Tests\TestCase;

use function Swoole\Coroutine\go;

/**
 * @internal
 * @covers \Swoole\Coroutine\Http2\MultiplexClient
 */
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
        self::coRun(function () {
            $server = $this->startServer();
            $client = $this->newClient($server);
            try {
                $this->assertTrue($client->connect());

                // Assertions live outside the coroutines, and exceptions are collected rather
                // than left to escape: either would otherwise become an uncaught exception in a
                // child coroutine and abort the process instead of failing the test.
                $wg        = new WaitGroup();
                $responses = [];
                $errors    = [];
                for ($i = 1; $i <= 30; $i++) {
                    $wg->add();
                    go(function () use ($client, $wg, &$responses, &$errors, $i) {
                        try {
                            $responses[$i] = $client->request(self::newRequest('/', "payload-{$i}"), 10);
                        } catch (\Throwable $e) {
                            $errors[$i] = $e;
                        } finally {
                            $wg->done();
                        }
                    });
                }
                $wg->wait();

                $this->assertSame([], $errors);
                $this->assertCount(30, $responses);
                foreach ($responses as $i => $response) {
                    $this->assertInstanceOf(Response::class, $response);
                    $this->assertSame(200, $response->statusCode);
                    $this->assertSame("payload-{$i}", $response->data);
                }
            } finally {
                $client->close();
                $server->shutdown();
            }
        });
    }

    public function testRequestWithoutExplicitConnect(): void
    {
        self::coRun(function () {
            $server = $this->startServer();
            $client = $this->newClient($server);
            try {
                // request() must establish the connection on demand.
                $response = $client->request(self::newRequest('/', 'hello'));
                $this->assertInstanceOf(Response::class, $response);
                $this->assertSame('hello', $response->data);
            } finally {
                $client->close();
                $server->shutdown();
            }
        });
    }

    public function testRequestTimeout(): void
    {
        self::coRun(function () {
            $server = $this->startServer();
            $client = $this->newClient($server);
            try {
                $this->assertTrue($client->connect());

                $start = microtime(true);
                $this->assertFalse($client->request(self::newRequest('/slow', 'late', ['x-delay' => '1']), 0.2));
                $elapsed = microtime(true) - $start;
                $this->assertGreaterThan(0.15, $elapsed); // the timeout actually elapsed ...
                $this->assertLessThan(0.6, $elapsed);     // ... and did not degrade into the 1 s delay
                $this->assertSame(SWOOLE_ERROR_CO_TIMEDOUT, $client->errCode);

                // While this second request is in flight (~0.2 s to ~1.2 s), the late response of the
                // timed-out stream arrives (~1 s) and must be dropped — not delivered anywhere. The
                // second request uses no explicit timeout, exercising the fallback to the client's
                // `timeout` setting.
                $response = $client->request(self::newRequest('/slow', 'on-time', ['x-delay' => '1']));
                $this->assertInstanceOf(Response::class, $response);
                $this->assertSame('on-time', $response->data);

                // The late response must have been dropped, not parked: a parked channel or a
                // leftover abandoned-stream marker would linger (and block idle closing) forever.
                $reflection = new \ReflectionObject($client);
                $this->assertSame([], $reflection->getProperty('streamChannels')->getValue($client));
                $this->assertSame([], $reflection->getProperty('abandonedStreams')->getValue($client));
            } finally {
                $client->close();
                $server->shutdown();
            }
        });
    }

    public function testReconnectAfterServerClosesConnection(): void
    {
        self::coRun(function () {
            $server = $this->startServer();
            $client = $this->newClient($server);
            try {
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
            } finally {
                $client->close();
                $server->shutdown();
            }
        });
    }

    public function testCloseWithRequestInFlight(): void
    {
        self::coRun(function () {
            $server = $this->startServer();
            $client = $this->newClient($server);
            try {
                $this->assertTrue($client->connect());

                $wg     = new WaitGroup();
                $result = null;
                $error  = null;
                $wg->add();
                go(function () use ($client, $wg, &$result, &$error) {
                    try {
                        $result = $client->request(self::newRequest('/slow', 'in-flight'));
                    } catch (\Throwable $e) {
                        $error = $e;
                    } finally {
                        $wg->done();
                    }
                });
                Coroutine::sleep(0.1); // let the request get in flight

                $start = microtime(true);
                $this->assertTrue($client->close()); // there was a connection to tear down
                $this->assertFalse($client->close()); // closing an already-closed client is harmless
                $wg->wait();
                $this->assertNull($error);
                $this->assertFalse($result);
                $this->assertSame(SWOOLE_ERROR_CLIENT_NO_CONNECTION, $client->errCode);
                $this->assertLessThan(0.3, microtime(true) - $start); // woken by close(), not by a timeout

                // The reused stream ID of the new connection must work.
                $response = $client->request(self::newRequest('/', 'again'));
                $this->assertInstanceOf(Response::class, $response);
                $this->assertSame('again', $response->data);
            } finally {
                $client->close();
                $server->shutdown();
            }
        });
    }

    public function testConcurrentRequestsAfterClose(): void
    {
        self::coRun(function () {
            $server = $this->startServer();
            $client = $this->newClient($server);
            try {
                $this->assertTrue($client->connect());
                $this->assertInstanceOf(Response::class, $client->request(self::newRequest('/', 'warm-up')));
                $client->close();

                // All requests arriving while the connection is re-established must wait behind the
                // startup barrier and then succeed; none may send into the half-open connection.
                $wg        = new WaitGroup();
                $responses = [];
                $errors    = [];
                for ($i = 1; $i <= 10; $i++) {
                    $wg->add();
                    go(function () use ($client, $wg, &$responses, &$errors, $i) {
                        try {
                            $responses[$i] = $client->request(self::newRequest('/', "burst-{$i}"), 10);
                        } catch (\Throwable $e) {
                            $errors[$i] = $e;
                        } finally {
                            $wg->done();
                        }
                    });
                }
                $wg->wait();

                $this->assertSame([], $errors);
                $this->assertCount(10, $responses);
                foreach ($responses as $i => $response) {
                    $this->assertInstanceOf(Response::class, $response);
                    $this->assertSame("burst-{$i}", $response->data);
                }
            } finally {
                $client->close();
                $server->shutdown();
            }
        });
    }

    public function testIdleClose(): void
    {
        self::coRun(function () {
            $server  = $this->startServer();
            $keeper  = $this->newClient($server, ['heartbeat_idle_time' => 0]); // idle closing disabled
            $closer  = $this->newClient($server, ['heartbeat_check_interval' => 0.1, 'heartbeat_idle_time' => 0.5]);
            $clamped = $this->newClient($server, ['heartbeat_check_interval' => 0, 'heartbeat_idle_time' => 0.5]);
            try {
                foreach ([$keeper, $closer, $clamped] as $client) {
                    $this->assertTrue($client->connect());
                    $this->assertInstanceOf(Response::class, $client->request(self::newRequest('/', 'ping')));
                }

                // With a 0.5 s idle time and a 0.1 s check interval, the close lands within ~0.7 s.
                Coroutine::sleep(1.5);
                $this->assertFalse($closer->connected);
                $this->assertTrue($keeper->connected);
                // A non-positive check interval falls back to the 3 s default instead of disabling
                // the checker or spinning: this client has simply not been checked yet.
                $this->assertTrue($clamped->connected);

                // An idle-closed client reconnects transparently on the next request.
                $response = $closer->request(self::newRequest('/', 'wake'));
                $this->assertInstanceOf(Response::class, $response);
                $this->assertSame('wake', $response->data);

                Coroutine::sleep(2.2); // past the clamped client's first (default-interval) check
                $this->assertFalse($clamped->connected);
                $this->assertTrue($keeper->connected);
            } finally {
                $keeper->close();
                $closer->close();
                $clamped->close();
                $server->shutdown();
            }
        });
    }

    public function testIdleCloseSparesInFlightRequest(): void
    {
        self::coRun(function () {
            $server = $this->startServer();
            $client = $this->newClient($server, ['heartbeat_check_interval' => 0.1, 'heartbeat_idle_time' => 0.2]);
            try {
                $this->assertTrue($client->connect());

                // The request outlasts the idle threshold by far; a connection with an in-flight
                // request must not be closed as idle.
                $response = $client->request(self::newRequest('/slow', 'patient', ['x-delay' => '1.5']));
                $this->assertInstanceOf(Response::class, $response);
                $this->assertSame('patient', $response->data);
            } finally {
                $client->close();
                $server->shutdown();
            }
        });
    }

    public function testRequestAgainstUnreachableServer(): void
    {
        self::coRun(function () {
            // Reserve a port with a throwaway server, then shut it down so nothing listens on it.
            $server = $this->startServer();
            $port   = $server->port;
            $server->shutdown();
            Coroutine::sleep(0.05);

            $client = new MultiplexClient('127.0.0.1', $port);
            $client->set(['timeout' => 0.5]);
            try {
                $this->assertFalse($client->request(self::newRequest('/', 'nobody-home')));
                // The errCode must be connect()'s precise one (e.g. ECONNREFUSED), not the generic
                // no-connection code a doomed send() would have overwritten it with.
                $this->assertNotSame(0, $client->errCode);
                $this->assertNotSame(SWOOLE_ERROR_CLIENT_NO_CONNECTION, $client->errCode);
            } finally {
                $client->close();
            }
        });
    }

    public function testFrameLevelApiIsDisabled(): void
    {
        self::coRun(function () {
            $client = new MultiplexClient('127.0.0.1', 1);
            foreach (['recv' => [], 'read' => [], 'write' => [1, 'x'], 'goaway' => []] as $method => $args) {
                try {
                    $client->{$method}(...$args);
                    $this->fail("{$method}() must throw");
                } catch (\BadMethodCallException $e) {
                    $this->assertNotSame('', $e->getMessage());
                }
            }
        });
    }

    private function startServer(): Server
    {
        $server = new Server('127.0.0.1', 0);
        $server->set(['open_http2_protocol' => true]);
        $server->handle('/slow', function ($request, $response) {
            Coroutine::sleep((float) ($request->header['x-delay'] ?? 0.5));
            $response->end($request->getContent());
        });
        $server->handle('/bye', function ($request, $response) {
            $response->close();
        });
        $server->handle('/', function ($request, $response) {
            // Respond after a small random delay so that concurrent responses interleave out of
            // order, like real multiplexed traffic; dispatching by stream ID must not rely on
            // responses arriving in request order.
            Coroutine::sleep(random_int(1, 3) / 1000);
            $response->end($request->getContent());
        });
        go(function () use ($server) {
            $server->start();
        });
        return $server;
    }

    private function newClient(Server $server, array $settings = []): MultiplexClient
    {
        $client = new MultiplexClient('127.0.0.1', $server->port);
        $client->set($settings + ['timeout' => 5]);
        return $client;
    }

    private static function newRequest(string $path, string $data, array $headers = []): Request
    {
        $request          = new Request();
        $request->method  = 'POST';
        $request->path    = $path;
        $request->data    = $data;
        $request->headers = $headers;
        return $request;
    }
}
