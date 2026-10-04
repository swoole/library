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
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Swoole\Coroutine\WaitGroup;
use Swoole\FastCGI\HttpRequest;
use Swoole\FastCGI\HttpResponse;
use Swoole\FastCGI\Record\EndRequest;
use Swoole\FastCGI\Record\Stdout;
use Swoole\Http\Response;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Coroutine\FastCGI\Proxy
 */
class ProxyTest extends TestCase
{
    public function testAConnectionForEveryRequestByDefault(): void
    {
        self::coRun(function (): void {
            $proxy = self::getProxy('tcp://php-fpm:9000');
            for ($i = 0; $i < 3; $i++) {
                $proxy->pass(self::getRequest(), new Response());
            }

            self::assertCount(3, $proxy->clients);
            self::assertCount(3, $proxy->responses);
            foreach ($proxy->clients as $client) {
                self::assertFalse($client->isConnected());
            }
        });
    }

    public function testConnectionPool(): void
    {
        self::coRun(function (): void {
            $proxy = self::getProxy('tcp://php-fpm:9000')->withConnectionPool(2);
            for ($i = 0; $i < 6; $i++) {
                $proxy->pass($request = self::getRequest(), new Response());
                self::assertFalse($request->getKeepConn(), 'The request is left as it was.');
            }

            self::assertCount(1, $proxy->clients, 'One request at a time needs one connection.');
            self::assertTrue($proxy->clients[0]->isConnected());
            self::assertCount(6, $proxy->responses);
            // A process of PHP-FPM serves its connection and nothing else for as long as it is open.
            self::assertCount(1, array_unique(array_map(static fn (HttpResponse $response): string => $response->getBody(), $proxy->responses)));
            self::assertGreaterThan(0, (int) $proxy->responses[0]->getBody());

            $proxy->withConnectionPool(0);
        });
    }

    public function testConnectionPoolUnderConcurrency(): void
    {
        self::coRun(function (): void {
            $proxy     = self::getProxy('tcp://php-fpm:9000')->withConnectionPool(2);
            $waitGroup = new WaitGroup();
            for ($i = 0; $i < 8; $i++) {
                $waitGroup->add();
                Coroutine::create(static function () use ($proxy, $waitGroup): void {
                    $proxy->pass(self::getRequest(), new Response());
                    $waitGroup->done();
                });
            }
            $waitGroup->wait();

            self::assertCount(2, $proxy->clients);
            self::assertCount(8, $proxy->responses);
            foreach ($proxy->responses as $response) {
                self::assertSame(200, $response->getStatusCode());
            }

            $proxy->withConnectionPool(0);
        });
    }

    /**
     * The server may close a connection kept open. The request that finds it closed is sent over a new one.
     */
    public function testConnectionClosedByTheServer(): void
    {
        self::coRun(function (): void {
            $connections = 0;
            $server      = new Server('127.0.0.1', 0);
            $server->handle(static function (Connection $connection) use (&$connections): void {
                $connections++;
                $connection->recv();
                $connection->send(new Stdout("Content-Type: text/plain\r\n\r\nconnection {$connections}") . new Stdout('') . new EndRequest());
                $connection->close();
            });
            Coroutine::create(static fn () => $server->start());

            $proxy = self::getProxy("tcp://127.0.0.1:{$server->port}")->withConnectionPool(1);
            $proxy->pass(self::getRequest(), new Response());
            $proxy->pass(self::getRequest(), new Response());
            $server->shutdown();

            self::assertSame(['connection 1', 'connection 2'], array_map(static fn (HttpResponse $response): string => $response->getBody(), $proxy->responses));
            self::assertCount(1, $proxy->clients, 'The client of the pool connected again.');
        });
    }

    public function testNoConnectionIsFree(): void
    {
        self::coRun(function (): void {
            $server = new Server('127.0.0.1', 0);
            $server->handle(static function (Connection $connection): void {
                // Never answers.
                $connection->recv();
                Coroutine::sleep(1);
                $connection->close();
            });
            Coroutine::create(static fn () => $server->start());

            $proxy  = self::getProxy("tcp://127.0.0.1:{$server->port}")->withConnectionPool(1)->withTimeout(0.2);
            $errors = [];
            for ($i = 0; $i < 2; $i++) {
                Coroutine::create(static function () use ($proxy, &$errors): void {
                    try {
                        $proxy->pass(self::getRequest(), new Response());
                    } catch (Client\Exception|\RuntimeException $e) {
                        $errors[] = $e::class . ': ' . $e->getMessage();
                    }
                });
            }
            Coroutine::sleep(0.5);
            $server->shutdown();

            // One request has the connection and times out; the other one finds no connection free in time.
            self::assertCount(2, $errors);
            self::assertContains('RuntimeException: No connection is available from the pool within 0.2 seconds', $errors);
        });
    }

    public function testNegativeSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::getProxy('tcp://php-fpm:9000')->withConnectionPool(-1);
    }

    private static function getRequest(): HttpRequest
    {
        return (new HttpRequest())->withScriptFilename(DOCUMENT_ROOT . '/pid.php');
    }

    /**
     * A proxy that keeps the clients it makes and the responses it gets.
     */
    private static function getProxy(string $url): Proxy
    {
        return new class($url, DOCUMENT_ROOT) extends Proxy {
            /** @var array<Client> */
            public array $clients = [];

            /** @var array<HttpResponse> */
            public array $responses = [];

            public function translateResponse(HttpResponse $response, Response $userResponse): void
            {
                $this->responses[] = $response;
            }

            protected function createClient(): Client
            {
                return $this->clients[] = parent::createClient();
            }
        };
    }
}
