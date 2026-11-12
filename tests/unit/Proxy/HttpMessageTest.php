<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Proxy;

use Swoole\Proxy\HttpMessage;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Proxy\HttpMessage
 */
class HttpMessageTest extends TestCase
{
    public function testRequestTargetKeepsTheQueryAndRejectsAnythingElse(): void
    {
        $this->assertSame('/search?0', HttpMessage::requestTarget('/search?0'));
        $this->assertSame('/item?0', HttpMessage::requestTargetFromServer([
            'request_uri'  => '/item',
            'query_string' => '0',
        ]));
        $this->assertSame('/item', HttpMessage::requestTargetFromServer(['request_uri' => '/item']));
        $this->assertSame('/', HttpMessage::requestTarget('/'));
        $this->assertNull(HttpMessage::requestTarget(''));
        $this->assertNull(HttpMessage::requestTarget('search'));
        $this->assertNull(HttpMessage::requestTarget('http://example.test/'));
        $this->assertNull(HttpMessage::requestTarget("/a\r\nHost: evil"));
        $this->assertNull(HttpMessage::requestTarget('/a#b'));
    }

    public function testMethodsAreMatchedIgnoringCase(): void
    {
        $this->assertTrue(HttpMessage::isMethodAllowed('get'));
        $this->assertTrue(HttpMessage::isMethodAllowed('OPTIONS'));
        $this->assertFalse(HttpMessage::isMethodAllowed('TRACE'));
        $this->assertFalse(HttpMessage::isMethodAllowed('CONNECT'));
        $this->assertFalse(HttpMessage::isMethodAllowed(''));
    }

    public function testRequestHeadersDropHopByHopAndInjectedLines(): void
    {
        $headers = HttpMessage::requestHeaders([
            'Host'            => 'app.test',
            'Authorization'   => 'Bearer t',
            'X-Request-Id'    => 'abc',
            'Connection'      => 'close, X-Drop',
            'X-Drop'          => 'secret',
            'Content-Length'  => '4',
            'X-Bad'           => "one\r\nX-Injected: yes",
            'X-List'          => ['a', 'b'],
        ], '10.0.0.8', 8080, false, false, '203.0.113.4');

        $this->assertSame('10.0.0.8:8080', $headers['host']);
        $this->assertSame('Bearer t', $headers['authorization']);
        $this->assertSame('abc', $headers['x-request-id']);
        $this->assertSame('a, b', $headers['x-list']);
        $this->assertSame('203.0.113.4', $headers['x-forwarded-for']);
        $this->assertArrayNotHasKey('connection', $headers);
        $this->assertArrayNotHasKey('x-drop', $headers);
        $this->assertArrayNotHasKey('content-length', $headers);
        $this->assertArrayNotHasKey('x-bad', $headers);
    }

    public function testPreserveHostAndAppendForwardedFor(): void
    {
        $headers = HttpMessage::requestHeaders([
            'Host'            => 'app.test',
            'X-Forwarded-For' => '198.51.100.2',
        ], '10.0.0.8', 80, false, true, '203.0.113.4');

        $this->assertSame('app.test', $headers['host']);
        $this->assertSame('198.51.100.2, 203.0.113.4', $headers['x-forwarded-for']);
    }

    public function testHostHeaderWrapsIpv6(): void
    {
        $this->assertSame('[::1]', HttpMessage::hostHeader('::1', 80, false));
        $this->assertSame('[::1]:8443', HttpMessage::hostHeader('::1', 8443, true));
        $this->assertSame('10.0.0.8', HttpMessage::hostHeader('10.0.0.8', 443, true));
    }

    public function testResponseHeadersKeepSetCookieApart(): void
    {
        $fields = HttpMessage::responseHeaders([
            'content-type'      => 'text/plain',
            'transfer-encoding' => 'chunked',
            'content-length'    => '4',
            'x-multi'           => ['one', 'two'],
            'set-cookie'        => ['a=1', 'b=2'],
        ], ['id=1; HttpOnly', "bad\r\ncookie"]);

        $this->assertSame('text/plain', $fields['headers']['content-type']);
        $this->assertSame(['one', 'two'], $fields['headers']['x-multi']);
        $this->assertArrayNotHasKey('transfer-encoding', $fields['headers']);
        $this->assertArrayNotHasKey('content-length', $fields['headers']);
        $this->assertArrayNotHasKey('set-cookie', $fields['headers']);
        $this->assertSame(['id=1; HttpOnly'], $fields['cookies']);
    }
}
