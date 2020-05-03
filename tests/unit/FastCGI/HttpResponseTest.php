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
    public function dataHeaders(): array
    {
        return [
            [
                [
                    'X-Powered-By' => 'PHP/7.4.5',
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'Link' => '<http://127.0.0.1/wp-json/>; rel="https://api.w.org/"',
                ],
                <<<'EOT'
X-Powered-By: PHP/7.4.5
Content-Type: text/html; charset=UTF-8
Link: <http://127.0.0.1/wp-json/>; rel="https://api.w.org/"

Hello world!
EOT,
                'default headers from WordPress homepage',
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
                    'X-Powered-By' => 'PHP/7.4.5',
                    'Content-type' => 'text/html; charset=UTF-8',
                ],
                DOCUMENT_ROOT . '/non-existing-script.php',
                'Test headers returned from a non-existing script',
            ],
            [
                [
                    'X-Powered-By' => 'PHP/7.4.5',
                    'Link' => '<http://127.0.0.1/wp-json/>; rel="https://api.w.org/"',
                    'Content-type' => 'text/html; charset=UTF-8',
                ],
                DOCUMENT_ROOT . '/header0.php',
                'Test headers returned from script "header0.php" (a default header from WordPress)',
            ],
            [
                [
                    'X-Powered-By' => 'PHP/7.4.5',
                    'Content-Type' => 'application/json',
                ],
                DOCUMENT_ROOT . '/header1.php',
                'Test headers returned from script "header1.php" (an HTTP header without space after the colon)',
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
                $client = new Client('php-fpm', 9000);
                $response = $client->execute((new HttpRequest())->withScriptFilename($filename));
                self::assertSame($expectedHeaders, $response->getHeaders(), $message);
            }
        );
    }
}
