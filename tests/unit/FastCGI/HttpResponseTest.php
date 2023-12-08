<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\FastCGI;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\FastCGI\Client;
use Swoole\FastCGI\Record\EndRequest;
use Swoole\FastCGI\Record\Stdout;

/**
 * @internal
 * @coversNothing
 */
class HttpResponseTest extends TestCase
{
    /**
     * @var string
     */
    protected $poweredBy = 'PHP/' . PHP_VERSION;

    public function dataHeaders(): array
    {
        return [
            [
                [
                    'X-Powered-By' => $this->poweredBy,
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'Link'         => '<http://127.0.0.1/wp-json/>; rel="https://api.w.org/"',
                ],
                "X-Powered-By: {$this->poweredBy}
Content-Type: text/html; charset=UTF-8
Link: <http://127.0.0.1/wp-json/>; rel=\"https://api.w.org/\"

Hello world!",
                'default headers from WordPress homepage',
            ],
            [
                [
                    'X-Foo0' => 'Bar0',
                    'X-Foo1' => 'Bar1',
                    'X-Foo3' => 'Bar3',
                    'X-Foo4' => 'Bar4',
                    'X-Foo5' => 'Bar5',
                ],
                'X-Foo0: Bar0
X-Foo1:Bar1
X-Foo2 Bar2
X-Foo3:Bar3 
 X-Foo4:Bar4
 X-Foo5:  Bar5 

Hello world!',
                'test variations of HTTP headers',
            ],
        ];
    }

    /**
     * @dataProvider dataHeaders
     * @covers \Swoole\FastCGI\HttpResponse
     */
    public function testHeaders(array $expectedHeaders, string $contentData, string $message): void
    {
        $contentData = str_replace("\n", "\r\n", $contentData); // Our files uses LF but not CRLF.
        self::assertSame($expectedHeaders, (new HttpResponse([new Stdout($contentData), new EndRequest()]))->getHeaders(), $message);
    }

    public function dataHeadersFromFPM(): array
    {
        return [
            [
                [
                    'X-Powered-By' => 'to be replaced',
                    'Content-type' => 'text/html; charset=UTF-8',
                ],
                DOCUMENT_ROOT . '/non-existing-script.php',
                'Test headers returned from a non-existing script',
            ],
            [
                [
                    'X-Powered-By' => 'to be replaced',
                    'Link'         => '<http://127.0.0.1/wp-json/>; rel="https://api.w.org/"',
                    'Content-type' => 'text/html; charset=UTF-8',
                ],
                DOCUMENT_ROOT . '/header0.php',
                'Test headers returned from script "header0.php" (a default header from WordPress)',
            ],
            [
                [
                    'X-Powered-By' => 'to be replaced',
                    'Content-Type' => 'application/json',
                ],
                DOCUMENT_ROOT . '/header1.php',
                'Test headers returned from script "header1.php" (an HTTP header without space after the colon)',
            ],
            [
                [
                    'X-Powered-By' => 'to be replaced',
                    'X-Foo0'       => 'Bar0',
                    'X-Foo1'       => 'Bar1',
                    'X-Foo3'       => 'Bar3',
                    'X-Foo4'       => 'Bar4',
                    'X-Foo5'       => 'Bar5',
                    'Content-type' => 'text/html; charset=UTF-8',
                ],
                DOCUMENT_ROOT . '/header2.php',
                'Test variations of HTTP headers',
            ],
        ];
    }

    /**
     * @dataProvider dataHeadersFromFPM
     * @covers \Swoole\FastCGI\HttpResponse
     */
    public function testHeadersFromFPM(array $expectedHeaders, string $filename, string $message): void
    {
        Coroutine\run(
            function () use ($expectedHeaders, $filename, $message) {
                $client   = new Client('php-fpm', 9000);
                $response = $client->execute((new HttpRequest())->withScriptFilename($filename));

                /*
                 * Unit tests run in the Swoole image, thus we can't get the PHP-FPM version directly when running tests.
                 * Here we we override expected HTTP header "X-Powered-By" with whatever returned from PHP-FPM.
                 */
                $expectedHeaders['X-Powered-By'] = $response->getHeaders()['X-Powered-By'];
                self::assertSame($expectedHeaders, $response->getHeaders(), $message);
            }
        );
    }

    public function dataStatus(): array
    {
        return [
            [
                400,
                'Bad Request',
                'Status: 400 Bad Request

Hello world!',
                'test HTTP status overridden with reason phrase included',
            ],
            [
                401,
                'Unauthorized',
                'Status: 401

Hello world!',
                'test HTTP status overridden without reason phrase included',
            ],
            [
                402,
                ' Payment Required',
                'Status:  402  Payment Required  

Hello world!',
                'test HTTP status overridden with reason phrase and extra spaces included',
            ],
            [
                403,
                'Forbidden',
                'Status:  403  

Hello world!',
                'test HTTP status overridden with extra spaces included, but no reason phrase',
            ],
        ];
    }

    /**
     * @dataProvider dataStatus
     * @covers \Swoole\FastCGI\HttpResponse
     */
    public function testStatus(mixed $expectedStatusCode, mixed $expectedReasonPhrase, string $contentData): void
    {
        $contentData = str_replace("\n", "\r\n", $contentData); // Our files uses LF but not CRLF.
        $response    = new HttpResponse([new Stdout($contentData), new EndRequest()]);
        self::assertSame($expectedStatusCode, $response->getStatusCode(), 'test status code returned');
        self::assertSame($expectedReasonPhrase, $response->getReasonPhrase(), 'test reason phrase');
    }

    public function dataStatusFromFPM(): array
    {
        return [
            [
                400,
                'Bad Request',
                DOCUMENT_ROOT . '/status0.php',
                'test HTTP status overridden with reason phrase included',
            ],
            [
                401,
                'Unauthorized',
                DOCUMENT_ROOT . '/status1.php',
                'test HTTP status overridden without reason phrase included',
            ],
            [
                402,
                ' Payment Required',
                DOCUMENT_ROOT . '/status2.php',
                'test HTTP status overridden with reason phrase and extra spaces included',
            ],
            [
                403,
                'Forbidden',
                DOCUMENT_ROOT . '/status3.php',
                'test HTTP status overridden with extra spaces included, but no reason phrase',
            ],
        ];
    }

    /**
     * @dataProvider dataStatusFromFPM
     * @covers \Swoole\FastCGI\HttpResponse
     */
    public function testStatusFromFPM(mixed $expectedStatusCode, mixed $expectedReasonPhrase, string $filename): void
    {
        Coroutine\run(
            function () use ($expectedStatusCode, $expectedReasonPhrase, $filename) {
                $client   = new Client('php-fpm', 9000);
                $response = $client->execute((new HttpRequest())->withScriptFilename($filename));
                self::assertSame($expectedStatusCode, $response->getStatusCode(), 'test status code returned');
                self::assertSame($expectedReasonPhrase, $response->getReasonPhrase(), 'test reason phrase');
            }
        );
    }
}
