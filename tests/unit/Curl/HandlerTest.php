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
 * @runTestsInSeparateProcesses
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
            self::assertSame('', $httpPrimaryIp);
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
