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
use Swoole\Coroutine\Socket;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers ::swoole_curl_getinfo
 * @covers ::swoole_curl_getinfo_integer
 * @covers ::swoole_curl_setopt
 * @covers \Swoole\Curl\Handler
 */
class CompatibilityTest extends TestCase
{
    public function testWriteCallbackDoesNotRetainItsHandle(): void
    {
        $handler   = new Handler();
        $reference = \WeakReference::create($handler);
        $handler->setOpt(CURLOPT_WRITEFUNCTION, static fn ($handle, string $data): int => strlen($data));
        unset($handler);
        self::assertNull($reference->get(), 'The internal write callback must not retain its owning handle');
    }

    public function testDefaultInfoAndCloseMatchNativeCurl(): void
    {
        $native = null;
        self::coRun(static function () use (&$native): void {
            // The child never enables runtime hooks, giving a native CurlHandle in either library test mode.
            $code = <<<'PHP'
                $handle = curl_init();
                $result = ['info' => curl_getinfo($handle), 'private' => curl_getinfo($handle, CURLINFO_PRIVATE)];
                $result['unknown_info'] = curl_getinfo($handle, 999999);
                $result['zero_info'] = curl_getinfo($handle, 0);
                try { curl_setopt($handle, 999999, true); } catch (Throwable $e) { $result['invalid_option'] = get_class($e); }
                @curl_close($handle);
                $result['after_close'] = curl_getinfo($handle);
                echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                PHP;
            $process = proc_open([PHP_BINARY, '-d', 'swoole.enable_library=Off', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output  = stream_get_contents($pipes[1]);
            $error   = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertSame('', $error);
            $native = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        });
        $handler  = new Handler();
        $expected = $native['info'];
        $actual   = $handler->getInfo();
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
        self::assertSame($native['private'], swoole_curl_getinfo($handler, CURLINFO_PRIVATE));
        self::assertSame($native['unknown_info'], swoole_curl_getinfo($handler, 999999));
        self::assertSame($native['zero_info'], swoole_curl_getinfo($handler, 0));
        swoole_curl_close($handler);
        swoole_curl_close($handler);
        $expected = $native['after_close'];
        $actual   = swoole_curl_getinfo($handler, null);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
        self::assertTrue(swoole_curl_setopt($handler, CURLOPT_URL, 'http://127.0.0.1/'));
        self::assertSame(\ValueError::class, $native['invalid_option']);
        $this->expectException(\ValueError::class);
        swoole_curl_setopt($handler, 999999, true);
    }

    public function testKnownUnsupportedOptionReturnsFalse(): void
    {
        $handler = new Handler();
        self::assertFalse(swoole_curl_setopt($handler, CURLOPT_CONNECT_ONLY, true));
        self::assertSame(CURLE_UNKNOWN_TELNET_OPTION, swoole_curl_errno($handler));
        if (defined('CURLINFO_TOTAL_TIME_T')) {
            self::assertFalse(swoole_curl_getinfo_integer([], CURLINFO_TOTAL_TIME_T));
            self::assertFalse(swoole_curl_getinfo_integer(['total_time' => null], CURLINFO_TOTAL_TIME_T));
            self::assertFalse(swoole_curl_getinfo_integer(['total_time' => 'invalid'], CURLINFO_TOTAL_TIME_T));
        }
        self::assertFalse(swoole_curl_getinfo_integer([], 999999));
    }

    public function testDuplicateHeadersAreSentAsSeparateFields(): void
    {
        self::coRun(static function (): void {
            $listener = new Socket(AF_INET, SOCK_STREAM, 0);
            $listener->bind('127.0.0.1', 0);
            $listener->listen();
            $request = '';
            $error   = null;
            Coroutine::create(static function () use ($listener, &$request, &$error): void {
                $peer = null;
                try {
                    $peer = $listener->accept(2);
                    while (!str_contains($request, "\r\n\r\n")) {
                        $part = $peer->recv(8192, 2);
                        if ($part === false || $part === '') {
                            throw new \RuntimeException('Request ended before headers');
                        }
                        $request .= $part;
                    }
                    $peer->sendAll("HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                } catch (\Throwable $e) {
                    $error = $e;
                } finally {
                    if ($peer instanceof Socket) {
                        $peer->close();
                    }
                }
            });
            try {
                $handler = new Handler('http://127.0.0.1:' . $listener->getsockname()['port']);
                $handler->setOpt(CURLOPT_RETURNTRANSFER, true);
                $handler->setOpt(CURLINFO_HEADER_OUT, true);
                $headers = ['X-Foo: a', 'X-Foo: b', 'x-foo: c', 'Accept: text/plain', 'Accept: application/json', 'Cookie: a=1', 'Cookie: b=2'];
                $handler->setOpt(CURLOPT_HTTPHEADER, $headers);
                self::assertSame('', $handler->exec(), $handler->error());
                self::assertNull($error);
                foreach ($headers as $header) {
                    self::assertStringContainsString($header . "\r\n", $request);
                    self::assertStringContainsString($header . "\r\n", $handler->getInfo(CURLINFO_HEADER_OUT));
                }
                self::assertStringNotContainsString('Array', $request);
                $info = $handler->getInfo();
                foreach (['appconnect', 'connect', 'namelookup', 'pretransfer', 'redirect', 'starttransfer', 'total'] as $name) {
                    $constant = 'CURLINFO_' . strtoupper($name) . '_TIME_T';
                    if (defined($constant)) {
                        self::assertIsInt($info[$name . '_time_us']);
                        self::assertSame($info[$name . '_time_us'], $handler->getInfo(constant($constant)));
                    }
                }
                if (defined('CURLINFO_EFFECTIVE_METHOD')) {
                    self::assertSame('GET', $info['effective_method']);
                }
                self::assertArrayNotHasKey('private', $info);
                self::assertArrayNotHasKey('appconnect_time', $info);
            } finally {
                $listener->close();
            }
        });
    }

    public function testDuplicateCredentialsAreRemovedOnCrossOriginRedirects(): void
    {
        self::coRun(static function (): void {
            $destination = new Server('127.0.0.1', 0);
            $destination->handle('/', static function ($request, $response): void {
                $response->end(json_encode(['method' => $request->server['request_method'], 'headers' => $request->header], JSON_THROW_ON_ERROR));
            });
            $source = new Server('127.0.0.1', 0);
            $source->handle('/', static function ($request, $response) use ($destination): void {
                $response->status(302);
                $response->header('Location', 'http://127.0.0.1:' . $destination->port . '/');
                $response->end('redirect');
            });
            Coroutine::create(static function () use ($destination): void { $destination->start(); });
            Coroutine::create(static function () use ($source): void { $source->start(); });
            try {
                $handler = new Handler('http://127.0.0.1:' . $source->port . '/');
                $handler->setOpt(CURLOPT_RETURNTRANSFER, true);
                $handler->setOpt(CURLOPT_FOLLOWLOCATION, true);
                $handler->setOpt(CURLOPT_POST, true);
                $handler->setOpt(CURLOPT_POSTFIELDS, 'body');
                $handler->setOpt(CURLOPT_HTTPHEADER, ['Authorization: first', 'Authorization: second', 'Cookie: first=1', 'Cookie: second=2', 'X-Test: visible']);
                $body = $handler->exec();
                self::assertIsString($body, $handler->error());
                $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('GET', $result['method']);
                self::assertArrayNotHasKey('authorization', $result['headers']);
                self::assertArrayNotHasKey('cookie', $result['headers']);
                self::assertSame('visible', $result['headers']['x-test']);
                if (defined('CURLINFO_EFFECTIVE_METHOD')) {
                    self::assertSame('GET', $handler->getInfo(CURLINFO_EFFECTIVE_METHOD));
                }
                self::assertTrue($handler->reset());
                self::assertTrue($handler->reset());
                self::assertSame(0, $handler->getInfo()['redirect_count']);
                if (defined('CURLINFO_EFFECTIVE_METHOD')) {
                    self::assertSame('GET', $handler->getInfo(CURLINFO_EFFECTIVE_METHOD));
                }
            } finally {
                $source->shutdown();
                $destination->shutdown();
            }
        });
    }
}
