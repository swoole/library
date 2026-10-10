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

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Tests\HookFlagsTrait;
use Swoole\Tests\TestCase;

/**
 * Class HandlerTest
 *
 * Most of the tests here query the httpbin service of docker-compose.yml, a local stand-in for httpbin.org.
 *
 * @internal
 * @covers \Swoole\Curl\Handler
 */
class HandlerTest extends TestCase
{
    use HookFlagsTrait;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::saveHookFlags();
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreHookFlags();
        parent::tearDownAfterClass();
    }

    public function setUp(): void
    {
        parent::setUp();
        self::setHookFlags(SWOOLE_HOOK_CURL);
    }

    public function testRedirect(): void
    {
        self::coRun(function () {
            $ch = curl_init(HTTPBIN_SERVER_URL . '/redirect/2');
            self::assertInstanceOf(Handler::class, $ch, 'Variable $ch should be a Handler object instead of a curl resource');

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            self::assertIsString(curl_exec($ch), self::curlErrorMessage($ch));
            self::assertEquals(200, curl_getinfo($ch, CURLINFO_HTTP_CODE), 'HTTP status code should be 200 instead of 302 once the redirects are followed');
        });
    }

    public function testToString(): void
    {
        self::coRun(function () {
            $ch = curl_init();
            self::assertMatchesRegularExpression('/Object\(\w+\) of type \(curl\)/', (string) $ch);
        });
    }

    public function testPrereqFunction(): void
    {
        self::coRun(function () {
            $invocations = 0;
            $args        = [];
            $ch          = curl_init(HTTPBIN_SERVER_URL . '/get');
            self::assertInstanceOf(Handler::class, $ch);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            self::assertTrue(curl_setopt($ch, CURLOPT_PREREQFUNCTION, function () use (&$invocations, &$args): int {
                $invocations++;
                $args = func_get_args();
                return CURL_PREREQFUNC_OK;
            }));

            $body = self::curlExecJson($ch);
            self::assertSame($body['headers']['Host'][0], HTTPBIN_SERVER_HOST, 'Returning CURL_PREREQFUNC_OK should let the request proceed');
            self::assertSame(1, $invocations, 'The callback should be invoked exactly once per request');
            self::assertCount(5, $args);
            [$handler, $primaryIp, $localIp, $primaryPort, $localPort] = $args;
            self::assertSame($ch, $handler);
            self::assertNotFalse(filter_var($primaryIp, FILTER_VALIDATE_IP), 'The primary IP should be a valid IP address');
            self::assertIsString($localIp);
            self::assertSame(HTTPBIN_SERVER_PORT, $primaryPort);
            self::assertIsInt($localPort);

            // Guzzle (7.13+) clears the callback with null on handle release.
            self::assertTrue(curl_setopt($ch, CURLOPT_PREREQFUNCTION, null));
            self::curlExecJson($ch);
            self::assertSame(1, $invocations, 'A cleared callback should no longer be invoked');
        });
    }

    public function testPrereqFunctionWithRedirects(): void
    {
        self::coRun(function () {
            $invocations = 0;
            $localPorts  = [];
            $ch          = curl_init(HTTPBIN_SERVER_URL . '/redirect/2');

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_PREREQFUNCTION, function ($handler, $primaryIp, $localIp, $primaryPort, $localPort) use (&$invocations, &$localPorts): int {
                $invocations++;
                $localPorts[] = $localPort;
                return CURL_PREREQFUNC_OK;
            });

            self::assertIsString(curl_exec($ch), self::curlErrorMessage($ch));
            self::assertEquals(200, curl_getinfo($ch, CURLINFO_HTTP_CODE));
            self::assertSame(3, $invocations, 'The callback should be invoked once per redirect hop');
            self::assertGreaterThan(0, $localPorts[1], 'Real socket addresses should be reported when the connection is reused');
        });
    }

    public function testPrereqFunctionAbort(): void
    {
        self::coRun(function () {
            $invocations = 0;
            $ch          = curl_init(HTTPBIN_SERVER_URL . '/get');

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_PREREQFUNCTION, function () use (&$invocations): int {
                $invocations++;
                return CURL_PREREQFUNC_ABORT;
            });

            self::assertFalse(curl_exec($ch), 'Returning CURL_PREREQFUNC_ABORT should abort the transfer');
            self::assertSame(1, $invocations);
            self::assertSame(CURLE_ABORTED_BY_CALLBACK, curl_errno($ch));
            self::assertSame('operation aborted by pre-request callback', curl_error($ch));
            self::assertSame(0, curl_getinfo($ch, CURLINFO_HTTP_CODE));
        });
    }

    public function testPrereqFunctionBadReturnValue(): void
    {
        self::coRun(function () {
            $ch = curl_init(HTTPBIN_SERVER_URL . '/get');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            curl_setopt($ch, CURLOPT_PREREQFUNCTION, fn (): int => 2);
            try {
                curl_exec($ch);
                self::fail('A ValueError should be thrown when the callback returns an unexpected integer');
            } catch (\ValueError $e) {
                self::assertSame('The CURLOPT_PREREQFUNCTION callback must return either CURL_PREREQFUNC_OK or CURL_PREREQFUNC_ABORT', $e->getMessage());
            }
            self::assertSame(CURLE_ABORTED_BY_CALLBACK, curl_errno($ch));

            curl_setopt($ch, CURLOPT_PREREQFUNCTION, fn () => 'ok');
            try {
                curl_exec($ch);
                self::fail('A TypeError should be thrown when the callback returns a non-integer');
            } catch (\TypeError $e) {
                self::assertSame('The CURLOPT_PREREQFUNCTION callback must return either CURL_PREREQFUNC_OK or CURL_PREREQFUNC_ABORT', $e->getMessage());
            }
        });
    }

    public function testCustomHost(): void
    {
        self::coRun(function () {
            $ip = Coroutine::gethostbyname(HTTPBIN_SERVER_HOST);
            $ch = curl_init("http://{$ip}/get");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . HTTPBIN_SERVER_HOST]);
            $body = self::curlExecJson($ch);
            self::assertSame($body['headers']['Host'][0], HTTPBIN_SERVER_HOST);
        });
    }

    public function testHeaderName(): void
    {
        self::coRun(function () {
            $ch = curl_init(HTTPBIN_SERVER_URL . '/get');
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $response = curl_exec($ch);
            self::assertIsString($response, self::curlErrorMessage($ch));
            $headers = substr($response, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
            $this->assertStringContainsStringIgnoringCase("\nDate:", $headers);
            $this->assertStringContainsStringIgnoringCase("\nContent-Type:", $headers);
            $this->assertStringContainsStringIgnoringCase("\nContent-Length:", $headers);
        });
    }

    public function testWriteFunction(): void
    {
        self::coRun(function () {
            $url     = HTTPBIN_SERVER_URL . '/get';
            $ch      = curl_init();
            $content = '';

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, $data) use (&$content): int {
                self::assertIsString($data);
                $content .= $data;
                return strlen($data);
            });

            self::assertTrue(curl_exec($ch), self::curlErrorMessage($ch));

            $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($body['headers']['Host'][0], HTTPBIN_SERVER_HOST);
        });
    }

    public function testResolve(): void
    {
        self::coRun(function () {
            $host = HTTPBIN_SERVER_HOST;
            $port = HTTPBIN_SERVER_PORT;
            $url  = HTTPBIN_SERVER_URL . '/get';
            $ip   = Coroutine::gethostbyname($host);

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$ip}"]);

            $body          = self::curlExecJson($ch);
            $httpPrimaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);

            self::assertSame($body['headers']['Host'][0], $host);
            self::assertEquals($body['url'], $url);
            self::assertEquals($ip, $httpPrimaryIp);
        });
    }

    public function testInvalidResolve(): void
    {
        self::coRun(function () {
            $host = HTTPBIN_SERVER_HOST;
            $port = HTTPBIN_SERVER_PORT;
            $url  = HTTPBIN_SERVER_URL . '/get';
            $ip   = '192.0.2.1'; // An incorrect IP in use: TEST-NET-1, guaranteed unroutable.
            $ch   = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$ip}"]);

            $body          = curl_exec($ch);
            $httpPrimaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
            self::assertFalse($body);
            self::assertSame('', $httpPrimaryIp);
        });
    }

    public function testResolve2(): void
    {
        self::coRun(function () {
            $host = HTTPBIN_SERVER_HOST;
            $port = HTTPBIN_SERVER_PORT;
            $url  = HTTPBIN_SERVER_URL . '/get';
            $ip   = Coroutine::gethostbyname($host);

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:192.0.2.1", "{$host}:{$port}:{$ip}"]);

            $body          = self::curlExecJson($ch);
            $httpPrimaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);

            self::assertSame($body['headers']['Host'][0], $host);
            self::assertEquals($body['url'], $url);
            self::assertEquals($ip, $httpPrimaryIp);
        });
    }

    public function testInvalidResolve2(): void
    {
        self::coRun(function () {
            $host = HTTPBIN_SERVER_HOST;
            $port = HTTPBIN_SERVER_PORT;
            $url  = HTTPBIN_SERVER_URL . '/get';
            $ip   = Coroutine::gethostbyname($host);
            $ch   = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$ip}", "+{$host}:{$port}:192.0.2.1"]);

            $body          = curl_exec($ch);
            $httpPrimaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
            self::assertFalse($body);
            self::assertSame('', $httpPrimaryIp);
        });
    }

    public function testInvalidResolve3(): void
    {
        self::coRun(function () {
            $host = HTTPBIN_SERVER_HOST;
            $port = HTTPBIN_SERVER_PORT;
            $url  = HTTPBIN_SERVER_URL . '/get';
            $ip   = Coroutine::gethostbyname($host);
            $ch   = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$ip}", "{$host}:{$port}:192.0.2.1"]);

            $body          = curl_exec($ch);
            $httpPrimaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
            self::assertFalse($body);
            self::assertSame('', $httpPrimaryIp);
        });
    }

    public function testResolve3(): void
    {
        self::coRun(function () {
            $host = HTTPBIN_SERVER_HOST;
            $port = HTTPBIN_SERVER_PORT;
            $url  = HTTPBIN_SERVER_URL . '/get';
            $ip   = Coroutine::gethostbyname($host);

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$ip}", "{$host}:{$port}:192.0.2.1", "-{$host}:{$port}:192.0.2.1"]);

            $body          = self::curlExecJson($ch);
            $httpPrimaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);

            self::assertSame($body['headers']['Host'][0], $host);
            self::assertEquals($body['url'], $url);
            self::assertSame($ip, $httpPrimaryIp);
        });
    }

    /**
     * Of several addresses given for a host, the next one is tried when one cannot be connected to.
     */
    public function testResolveWithSeveralAddresses(): void
    {
        self::coRun(function () {
            $host = HTTPBIN_SERVER_HOST;
            $port = HTTPBIN_SERVER_PORT;
            $url  = HTTPBIN_SERVER_URL . '/post';
            $ip   = Coroutine::gethostbyname($host);

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, 'foo=bar');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Test: several addresses']);
            // TEST-NET-1 and TEST-NET-2, which nothing can be connected to, and then the server.
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:192.0.2.1, 198.51.100.1,{$ip}"]);

            $body = self::curlExecJson($ch);

            self::assertSame($host, $body['headers']['Host'][0]);
            self::assertSame('several addresses', $body['headers']['X-Test'][0], 'The request is the same for every address.');
            self::assertSame(['bar'], $body['form']['foo']);
            self::assertSame($url, $body['url']);
            self::assertSame($ip, curl_getinfo($ch, CURLINFO_PRIMARY_IP));
            self::assertArrayNotHasKey('Cookie', $body['headers'], 'No empty Cookie header comes with the next address.');
        });
    }

    public function testResolveWithSeveralAddressesNoneOfWhichWorks(): void
    {
        self::coRun(function () {
            $host = HTTPBIN_SERVER_HOST;
            $port = HTTPBIN_SERVER_PORT;
            $ch   = curl_init();

            curl_setopt($ch, CURLOPT_URL, HTTPBIN_SERVER_URL . '/get');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:192.0.2.1,198.51.100.1"]);
            $tried = [];
            curl_setopt($ch, CURLOPT_PREREQFUNCTION, function ($handler, string $primaryIp) use (&$tried): int {
                $tried[] = $primaryIp;
                return CURL_PREREQFUNC_OK;
            });

            $start = microtime(true);
            self::assertFalse(curl_exec($ch));
            self::assertSame(['192.0.2.1', '198.51.100.1'], $tried, 'Every address was tried, in turn.');
            self::assertLessThan(1.8, microtime(true) - $start, 'CURLOPT_CONNECTTIMEOUT is the time to connect to any of the addresses.');
            self::assertNotSame(0, curl_errno($ch));
            self::assertSame('', curl_getinfo($ch, CURLINFO_PRIMARY_IP));
        });
    }

    /**
     * When every address failed, the next request on the handle starts again from the first address, and not from
     * the one that failed last.
     */
    public function testResolveStartsOverAfterEveryAddressFailed(): void
    {
        self::coRun(function () {
            // A port nothing listens on, for now: 127.0.0.1 and 127.0.0.2 refuse the connection at once. The socket
            // only finds a free port; it never listens.
            $socket = new Coroutine\Socket(AF_INET, SOCK_STREAM, 0);
            self::assertTrue($socket->bind('127.0.0.1', 0));
            $port = $socket->getsockname()['port'];
            $socket->close();

            $ch = curl_init("http://resolve.test:{$port}/");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_RESOLVE, ["resolve.test:{$port}:127.0.0.1,127.0.0.2"]);
            $tried = [];
            curl_setopt($ch, CURLOPT_PREREQFUNCTION, function ($handler, string $primaryIp) use (&$tried): int {
                $tried[] = $primaryIp;
                return CURL_PREREQFUNC_OK;
            });

            self::assertFalse(curl_exec($ch));
            self::assertSame(['127.0.0.1', '127.0.0.2'], $tried);

            $server = new Server('127.0.0.1', $port);
            Coroutine\go(function () use ($server) {
                $server->handle('/', function ($request, $response) {
                    $response->end('up');
                });
                $server->start();
            });

            try {
                $tried = [];
                self::assertSame('up', curl_exec($ch), 'The first address works now.');
                self::assertSame(['127.0.0.1'], $tried);
            } finally {
                $server->shutdown();
            }
        });
    }

    /**
     * The handler can authenticate the basic way only. With a value of CURLOPT_HTTPAUTH that excludes that way, a
     * password is not sent, for it not to go out as it is against the wish of the caller.
     */
    public function testHttpAuth(): void
    {
        self::coRun(function () {
            foreach ([CURLAUTH_BASIC, CURLAUTH_ANY, CURLAUTH_BASIC | CURLAUTH_DIGEST] as $value) {
                $ch = curl_init(HTTPBIN_SERVER_URL . '/basic-auth/user/secret');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                self::assertTrue(curl_setopt($ch, CURLOPT_HTTPAUTH, $value));
                curl_setopt($ch, CURLOPT_USERPWD, 'user:secret');
                curl_exec($ch);
                self::assertSame(200, curl_getinfo($ch, CURLINFO_HTTP_CODE));
            }

            // The order of the two options makes no difference.
            foreach ([CURLAUTH_DIGEST, CURLAUTH_NTLM, CURLAUTH_ANYSAFE, CURLAUTH_BEARER] as $value) {
                foreach ([[CURLOPT_HTTPAUTH, CURLOPT_USERPWD], [CURLOPT_USERPWD, CURLOPT_HTTPAUTH]] as $options) {
                    $ch = curl_init(HTTPBIN_SERVER_URL . '/basic-auth/user/secret');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    foreach ($options as $option) {
                        self::assertTrue(curl_setopt($ch, $option, $option === CURLOPT_USERPWD ? 'user:secret' : $value));
                    }
                    try {
                        curl_exec($ch);
                        self::fail('The password would be sent the basic way, which the option excludes.');
                    } catch (Exception $e) {
                        self::assertStringContainsString('CURLOPT_HTTPAUTH', $e->getMessage());
                    }
                }
            }
        });
    }

    /**
     * Without a password there is nothing to keep back, whatever the options allow.
     */
    public function testAuthOptionsWithoutPassword(): void
    {
        self::coRun(function () {
            foreach ([CURLAUTH_DIGEST, CURLAUTH_ANYSAFE, CURLAUTH_BEARER] as $value) {
                $ch = curl_init(HTTPBIN_SERVER_URL . '/get');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                self::assertTrue(curl_setopt($ch, CURLOPT_HTTPAUTH, $value));
                self::assertTrue(curl_setopt($ch, CURLOPT_PROXYAUTH, $value));
                curl_exec($ch);
                self::assertSame(200, curl_getinfo($ch, CURLINFO_HTTP_CODE), self::curlErrorMessage($ch));
            }
        });
    }

    /**
     * The same for the password of an HTTP proxy. The proxy is never connected to.
     */
    public function testProxyAuth(): void
    {
        self::coRun(function () {
            foreach ([CURLAUTH_DIGEST, CURLAUTH_NTLM, CURLAUTH_ANYSAFE] as $value) {
                $ch = curl_init(HTTPBIN_SERVER_URL . '/get');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1');
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                curl_setopt($ch, CURLOPT_PROXYPORT, 1);
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, 'user:secret');
                self::assertTrue(curl_setopt($ch, CURLOPT_PROXYAUTH, $value));
                try {
                    curl_exec($ch);
                    self::fail('The password would be sent the basic way, which the option excludes.');
                } catch (Exception $e) {
                    self::assertStringContainsString('CURLOPT_PROXYAUTH', $e->getMessage());
                }
            }

            $ch = curl_init(HTTPBIN_SERVER_URL . '/get');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1');
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            curl_setopt($ch, CURLOPT_PROXYPORT, 1);
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, 'user:secret');
            curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY);
            self::assertFalse(curl_exec($ch), 'Allowed, and failing only for there being no proxy.');
        });
    }

    public function testOptPrivate(): void
    {
        self::coRun(function () {
            $url     = HTTPBIN_SERVER_URL . '/get';
            $private = 'swoole';

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_PRIVATE, $private);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . HTTPBIN_SERVER_HOST]);

            $body        = self::curlExecJson($ch);
            $get_private = curl_getinfo($ch, CURLINFO_PRIVATE);

            self::assertEquals($private, $get_private);
            self::assertSame($body['headers']['Host'][0], HTTPBIN_SERVER_HOST);
        });
    }

    public function testRepeatHeader(): void
    {
        self::coRun(function () {
            $server = new Server('127.0.0.1', 0);
            Coroutine\go(function () use ($server) {
                $server->handle('/', function ($request, $response) {
                    $response->header('X-Test-Header1', ['value1', 'value2']);
                    $response->header('X-Test-Header2', 'value3');
                    $response->end();
                });
                $server->start();
            });
            $ch = curl_init('http://127.0.0.1:' . $server->port);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Test-Header: value1', 'X-Test-Header: value2']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $response   = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers    = substr($response, 0, $headerSize);
            $this->assertStringContainsStringIgnoringCase("x-test-header1: value1\r\n", $headers);
            $this->assertStringContainsStringIgnoringCase("x-test-header1: value2\r\n", $headers);
            $this->assertStringContainsStringIgnoringCase("x-test-header2: value3\r\n", $headers);
            $server->shutdown();
        });
    }

    /**
     * Execute a request and return its response decoded from JSON. Both a failed request and a response that
     * is not JSON raise an exception, failing the test on the spot.
     */
    private static function curlExecJson(mixed $ch): array
    {
        $response = curl_exec($ch);
        if (!is_string($response)) {
            throw new \RuntimeException(self::curlErrorMessage($ch));
        }

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function curlErrorMessage(mixed $ch): string
    {
        return sprintf('cURL request failed with error %d: %s', curl_errno($ch), curl_error($ch));
    }
}
