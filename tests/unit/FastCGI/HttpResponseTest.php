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
     * @covers \Swoole\FastCGI\Record\EndRequest
     */
    public function testHeaders(array $expectedHeaders, string $contentData, string $message): void
    {
        $contentData = str_replace("\n", "\r\n", $contentData); // Our files uses LF but not CRLF.
        self::assertSame($expectedHeaders, (new HttpResponse([new Stdout($contentData), new EndRequest()]))->getHeaders(), $message);
    }
}
