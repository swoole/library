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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Tests\HookFlagsTrait;
use Swoole\Tests\RetryTrait;

/**
 * Class HandlerTest
 *
 * Most of the tests here query httpbin.org. That service throttles, so every request that leaves this class
 * is wrapped in self::retry(): a failed request or an error page returned in place of the expected JSON is a
 * reason to ask again, not to fail the build. Assertions stay outside of the retried closures so that a
 * genuine mismatch is reported on the spot.
 *
 * @internal
 */
#[CoversClass(Handler::class)]
#[RunTestsInSeparateProcesses]
class HandlerTest extends TestCase
{
    use HookFlagsTrait;
    use RetryTrait;

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
        Coroutine\run(function () {
            $httpCode = self::retry(static function (): int {
                $ch = curl_init('http://alturl.com/6xb2v');
                self::assertInstanceOf(Handler::class, $ch, 'Variable $ch should be a Handler object instead of a curl resource');

                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                if (!is_string(curl_exec($ch))) {
                    throw new \RuntimeException(self::curlErrorMessage($ch));
                }
                return curl_getinfo($ch, CURLINFO_HTTP_CODE);
            });
            self::assertEquals(200, $httpCode, 'HTTP status code should be 200 instead of 301');
        });
    }

    public function testToString(): void
    {
        Coroutine\run(function () {
            $ch = curl_init();
            self::assertMatchesRegularExpression('/Object\(\w+\) of type \(curl\)/', (string) $ch);
        });
    }

    public function testCustomHost(): void
    {
        Coroutine\run(function () {
            $body = self::retry(static function (): array {
                $ip = Coroutine::gethostbyname('httpbin.org');
                $ch = curl_init("http://{$ip}/get");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: httpbin.org']);
                return self::curlExecJson($ch);
            });
            self::assertSame($body['headers']['Host'], 'httpbin.org');
        });
    }

    public function testHeaderName(): void
    {
        Coroutine\run(function () {
            $headers = self::retry(static function (): string {
                $ch = curl_init('http://httpbin.org/get');
                curl_setopt($ch, CURLOPT_HEADER, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $response = curl_exec($ch);
                if (!is_string($response)) {
                    throw new \RuntimeException(self::curlErrorMessage($ch));
                }
                return substr($response, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
            });
            $this->assertStringContainsStringIgnoringCase("\nDate:", $headers);
            $this->assertStringContainsStringIgnoringCase("\nContent-Type:", $headers);
            $this->assertStringContainsStringIgnoringCase("\nContent-Length:", $headers);
        });
    }

    public function testWriteFunction(): void
    {
        Coroutine\run(function () {
            $body = self::retry(static function (): array {
                $url     = 'https://httpbin.org/get';
                $ch      = curl_init();
                $content = '';

                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, $data) use (&$content): int {
                    self::assertIsString($data);
                    $content .= $data;
                    return strlen($data);
                });

                if (curl_exec($ch) !== true) {
                    throw new \RuntimeException(self::curlErrorMessage($ch));
                }

                return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            });
            self::assertSame($body['headers']['Host'], 'httpbin.org');
        });
    }

    public function testResolve(): void
    {
        Coroutine\run(function () {
            $host = 'httpbin.org';
            $url  = 'https://httpbin.org/get';
            $ip   = Coroutine::gethostbyname($host);

            [$body, $httpPrimaryIp] = self::retry(static function () use ($host, $url, $ip): array {
                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:443:{$ip}"]);

                return [self::curlExecJson($ch), curl_getinfo($ch, CURLINFO_PRIMARY_IP)];
            });

            self::assertSame($body['headers']['Host'], 'httpbin.org');
            self::assertEquals($body['url'], $url);
            self::assertEquals($ip, $httpPrimaryIp);
        });
    }

    public function testInvalidResolve(): void
    {
        Coroutine\run(function () {
            $host = 'httpbin.org';
            $url  = 'https://httpbin.org/get';
            $ip   = '127.0.0.1'; // An incorrect IP in use.
            $ch   = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:443:{$ip}"]);

            $body          = curl_exec($ch);
            $httpPrimaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
            self::assertFalse($body);
            self::assertSame('', $httpPrimaryIp);
        });
    }

    public function testResolve2(): void
    {
        Coroutine\run(function () {
            $host = 'httpbin.org';
            $url  = 'https://httpbin.org/get';
            $ip   = Coroutine::gethostbyname($host);

            [$body, $httpPrimaryIp] = self::retry(static function () use ($host, $url, $ip): array {
                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:443:127.0.0.1", "{$host}:443:{$ip}"]);

                return [self::curlExecJson($ch), curl_getinfo($ch, CURLINFO_PRIMARY_IP)];
            });

            self::assertSame($body['headers']['Host'], 'httpbin.org');
            self::assertEquals($body['url'], $url);
            self::assertEquals($ip, $httpPrimaryIp);
        });
    }

    public function testInvalidResolve2(): void
    {
        Coroutine\run(function () {
            $host = 'httpbin.org';
            $url  = 'https://httpbin.org/get';
            $ip   = Coroutine::gethostbyname($host);
            $ch   = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:443:{$ip}", "+{$host}:443:127.0.0.1"]);

            $body          = curl_exec($ch);
            $httpPrimaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
            self::assertFalse($body);
            self::assertSame('', $httpPrimaryIp);
        });
    }

    public function testInvalidResolve3(): void
    {
        Coroutine\run(function () {
            $host = 'httpbin.org';
            $url  = 'https://httpbin.org/get';
            $ip   = Coroutine::gethostbyname($host);
            $ch   = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:443:{$ip}", "{$host}:443:127.0.0.1"]);

            $body          = curl_exec($ch);
            $httpPrimaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
            self::assertFalse($body);
            self::assertSame('', $httpPrimaryIp);
        });
    }

    public function testResolve3(): void
    {
        Coroutine\run(function () {
            $host = 'httpbin.org';
            $url  = 'https://httpbin.org/get';
            $ip   = Coroutine::gethostbyname($host);

            [$body, $httpPrimaryIp] = self::retry(static function () use ($host, $url, $ip): array {
                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:443:{$ip}", "{$host}:443:127.0.0.1", "-{$host}:443:127.0.0.1"]);

                return [self::curlExecJson($ch), curl_getinfo($ch, CURLINFO_PRIMARY_IP)];
            });

            self::assertSame($body['headers']['Host'], 'httpbin.org');
            self::assertEquals($body['url'], $url);
            self::assertSame('', $httpPrimaryIp);
        });
    }

    public function testOptPrivate(): void
    {
        Coroutine\run(function () {
            $url     = 'https://httpbin.org/get';
            $private = 'swoole';

            [$body, $get_private] = self::retry(static function () use ($url, $private): array {
                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_PRIVATE, $private);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: httpbin.org']);

                return [self::curlExecJson($ch), curl_getinfo($ch, CURLINFO_PRIVATE)];
            });

            self::assertEquals($private, $get_private);
            self::assertSame($body['headers']['Host'], 'httpbin.org');
        });
    }

    public function testRepeatHeader(): void
    {
        Coroutine\run(function () {
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
     * is not JSON (an error page, typically) raise an exception, which self::retry() turns into one more
     * attempt.
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
