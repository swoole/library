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
use Swoole\Http\Request as ServerRequest;
use Swoole\Http\Response;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Coroutine\FastCGI\Proxy
 */
class ProxyTest extends TestCase
{
    public function testRepeatedRequestHeadersAreCombined(): void
    {
        $request = self::parseRequest('/inspect.php', true,
            "X-Test: one\r\nx-test: two\r\nX-Forwarded-For: 192.0.2.1\r\nX-Forwarded-For: 192.0.2.2\r\n");
        $translated = (new Proxy('tcp://php-fpm:9000', DOCUMENT_ROOT))->translateRequest($request);
        self::assertSame('one, two', $translated->getHeader('X-Test'));
        self::assertSame('192.0.2.1, 192.0.2.2', $translated->getHeader('X-Forwarded-For'));
    }

    /** @dataProvider paths */
    public function testScriptPathResolution(string $uri, string $script): void
    {
        // Path resolution uses the local filesystem; DOCUMENT_ROOT points inside the PHP-FPM container.
        $documentRoot = dirname(__DIR__, 3) . '/www/fastcgi';
        $translated   = (new Proxy('tcp://php-fpm:9000', $documentRoot))
            ->translateRequest(self::parseRequest($uri))
        ;
        self::assertSame($documentRoot . $script, $translated->getScriptFilename());
        self::assertSame($script, $translated->getScriptName());
        self::assertSame($script, $translated->getDocumentUri());
        self::assertSame($uri, $translated->getRequestUri());
    }

    public static function paths(): array
    {
        return [
            'literal plus'            => ['/plus+name.php', '/plus+name.php'],
            'encoded plus'            => ['/plus%2Bname.php', '/plus+name.php'],
            'encoded space'           => ['/plus%20name.php', '/plus name.php'],
            'decode once'             => ['/percent%252B.php', '/percent%2B.php'],
            'parent segment'          => ['/dir/../inspect.php?0', '/inspect.php'],
            'encoded parent segment'  => ['/dir/%2e%2e/inspect.php', '/inspect.php'],
            'current segment'         => ['/./inspect.php', '/inspect.php'],
            'repeated slash'          => ['//dir///../inspect.php', '/inspect.php'],
            'extensionless file'      => ['/LICENSE', '/LICENSE'],
            'dotted directory'        => ['/v1.2/', '/v1.2/index.php'],
            'directory without slash' => ['/v1.2', '/v1.2/index.php'],
            'trailing dot segment'    => ['/v1.2/.', '/v1.2/index.php'],
            'zero extension file'     => ['/file.0', '/file.0'],
        ];
    }

    public function testParentSegmentCannotEscapeTheRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Proxy('tcp://php-fpm:9000', DOCUMENT_ROOT))->translateRequest(self::parseRequest('/../outside.php'));
    }

    public function testCookiesAreForwardedWithoutReencoding(): void
    {
        foreach ([true, false] as $parseCookie) {
            $request = self::parseRequest('/inspect.php?0', $parseCookie,
                "Cookie: session=a%2Bb%20c; zero=0\r\ncookie: other=%3B%00\r\n");
            $translated = (new Proxy('tcp://php-fpm:9000', DOCUMENT_ROOT))->translateRequest($request);
            self::assertSame('session=a%2Bb%20c; zero=0; other=%3B%00', $translated->getHeader('cookie'));
            self::assertSame('0', $translated->getQueryString());
        }
    }

    public function testIndexResolutionPreservesTheOriginalRequestUri(): void
    {
        $proxy      = new Proxy('tcp://php-fpm:9000', DOCUMENT_ROOT);
        $translated = $proxy->translateRequest(self::parseRequest('/?query=1'));
        self::assertSame(DOCUMENT_ROOT . '/index.php', $translated->getScriptFilename());
        self::assertSame('/index.php', $translated->getScriptName());
        self::assertSame('/index.php', $translated->getDocumentUri());
        self::assertSame('/?query=1', $translated->getRequestUri());

        $translated = $proxy->withIndex('default.php')->translateRequest(self::parseRequest('/dir/'));
        self::assertSame('/dir/default.php', $translated->getScriptName());
        self::assertSame('/dir/', $translated->getRequestUri());
        self::assertSame('https', $proxy->withHttps(true)->translateRequest(self::parseRequest('/'))->getScheme());
    }

    public function testStaticFilesStayWithinTheCanonicalDocumentRoot(): void
    {
        $directory = sys_get_temp_dir() . '/swoole-fastcgi-' . bin2hex(random_bytes(8));
        mkdir($directory);
        mkdir($directory . '/www');
        mkdir($directory . '/www-sibling');
        file_put_contents($directory . '/www/inside.txt', 'inside');
        file_put_contents($directory . '/www-sibling/outside.txt', 'outside');
        try {
            $proxy = new Proxy('tcp://php-fpm:9000', $directory . '/www/../www');
            foreach (['inside.txt' => 200, '../www-sibling/outside.txt' => 404, 'missing.txt' => 404] as $path => $status) {
                $response = self::captureResponse();
                self::assertTrue($proxy->staticFileFiltrate(
                    (new HttpRequest())->withScriptFilename($directory . '/www/' . $path), $response));
                self::assertSame($status, $response->code);
                self::assertSame($status === 200 ? realpath($directory . '/www/inside.txt') : null, $response->file);
            }
            $response = self::captureResponse();
            self::assertTrue((new Proxy('tcp://php-fpm:9000', $directory . '/missing'))->staticFileFiltrate(
                (new HttpRequest())->withScriptFilename($directory . '/www/inside.txt'), $response));
            self::assertSame(404, $response->code);
        } finally {
            unlink($directory . '/www/inside.txt');
            unlink($directory . '/www-sibling/outside.txt');
            rmdir($directory . '/www');
            rmdir($directory . '/www-sibling');
            rmdir($directory);
        }
    }

    public function testTranslationPreservesRepeatedResponseHeaders(): void
    {
        $response = self::captureResponse();
        (new Proxy('tcp://php-fpm:9000'))->translateResponse(new HttpResponse([
            new Stdout("Link: </one>\r\nLink: </two>\r\nSet-Cookie: one=1\r\nSet-Cookie: two=2\r\n\r\nbody"),
            new EndRequest(),
        ]), $response);
        self::assertSame(['Link' => ['</one>', '</two>']], $response->header);
        self::assertSame(['one=1', 'two=2'], $response->cookie);
        self::assertSame('body', $response->body);
    }

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

    /**
     * A connection that the server closes after it has received a request may have run the request. Only a request of
     * an idempotent method is sent once more; a POST is not, and fails.
     */
    public function testConnectionClosedAfterTheRequestWasSent(): void
    {
        self::coRun(function (): void {
            foreach (['GET' => 3, 'POST' => 2] as $method => $expected) {
                $requests = 0;
                $server   = new Server('127.0.0.1', 0);
                $server->handle(static function (Connection $connection) use (&$requests): void {
                    // The first request is answered over a connection kept open; the second one is received, and the
                    // connection closed without an answer, as when the process that ran it dies. A request sent once
                    // more is answered.
                    while (!in_array($connection->recv(), ['', false], true)) {
                        if (++$requests === 2) {
                            break;
                        }
                        $connection->send(new Stdout("Content-Type: text/plain\r\n\r\nrequest {$requests}") . new Stdout('') . new EndRequest());
                        if ($requests > 2) {
                            break;
                        }
                    }
                    $connection->close();
                });
                Coroutine::create(static fn () => $server->start());

                $proxy = self::getProxy("tcp://127.0.0.1:{$server->port}")->withConnectionPool(1);
                $proxy->pass(self::getRequest()->withMethod($method), new Response());
                try {
                    $proxy->pass(self::getRequest()->withMethod($method), new Response());
                    $error = null;
                } catch (Client\Exception $e) {
                    $error = $e->getCode();
                }
                $server->shutdown();

                self::assertSame($expected, $requests, "The number of {$method} requests the server received.");
                if ($method === 'GET') {
                    self::assertNull($error, 'The GET request was sent once more, and answered.');
                    self::assertSame('request 3', $proxy->responses[1]->getBody());
                } else {
                    self::assertSame(SOCKET_ECONNRESET, $error, 'The POST request was not sent twice.');
                }
            }
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

    private static function parseRequest(string $uri, bool $parseCookie = true, string $headers = ''): ServerRequest
    {
        $request = ServerRequest::create(['parse_cookie' => $parseCookie]);
        $request->parse("GET {$uri} HTTP/1.1\r\nHost: localhost\r\n{$headers}\r\n");
        $request->server += ['server_port' => 80, 'remote_addr' => '127.0.0.1', 'remote_port' => 12345];
        return $request;
    }

    private static function captureResponse(): Response
    {
        return new class extends Response {
            public int $code = 200;

            public ?string $file = null;

            public ?string $body = null;

            public function status(int $http_code, string $reason = ''): bool
            {
                $this->code = $http_code;
                return true;
            }

            public function sendfile(string $filename, int $offset = 0, int $length = 0): bool
            {
                $this->file = $filename;
                return true;
            }

            public function end(?string $content = null): bool
            {
                $this->body = $content;
                return true;
            }
        };
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
