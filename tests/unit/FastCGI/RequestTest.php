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

use Swoole\FastCGI;
use Swoole\FastCGI\Record\BeginRequest;
use Swoole\FastCGI\Record\Params;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\FastCGI\Request
 */
class RequestTest extends TestCase
{
    /**
     * The body is sent as STDIN records of at most 65535 bytes each, followed by an empty one; the expected bytes
     * are built here by hand, independently of the record classes.
     *
     * @dataProvider dataBodyLength
     */
    public function testEncoding(int $bodyLength): void
    {
        $body    = self::body($bodyLength);
        $request = (new Request())->withBody($body);

        $expected = (string) new BeginRequest(FastCGI::RESPONDER, 0) . new Params([]) . new Params([]);
        if ($body !== '') {
            foreach (str_split($body, FastCGI::MAX_CONTENT_LENGTH) as $chunk) {
                $expected .= self::stdinRecord($chunk);
            }
        }
        $expected .= self::stdinRecord('');

        self::assertSame(bin2hex($expected), bin2hex((string) $request));
    }

    public static function dataBodyLength(): array
    {
        return [
            'no body'                                 => [0],
            'one byte'                                => [1],
            'one byte short of a padding-free record' => [7],
            'one padding-free record'                 => [8],
            'exactly one full record'                 => [FastCGI::MAX_CONTENT_LENGTH],
            'one full record plus one byte'           => [FastCGI::MAX_CONTENT_LENGTH + 1],
            'two full records plus one byte'          => [2 * FastCGI::MAX_CONTENT_LENGTH + 1],
            'three and a half records'                => [3 * FastCGI::MAX_CONTENT_LENGTH + 32768],
        ];
    }

    /**
     * A body of "0" is a body: it used to be dropped because it is "empty" to PHP.
     */
    public function testBodyOfASingleZero(): void
    {
        $request = (new Request())->withBody('0');

        $expected = (string) new BeginRequest(FastCGI::RESPONDER, 0) . new Params([]) . new Params([])
            . self::stdinRecord('0') . self::stdinRecord('');

        self::assertSame(bin2hex($expected), bin2hex((string) $request));
    }

    public function testKeepConn(): void
    {
        $request = (new Request())->withKeepConn(true);

        self::assertTrue($request->getKeepConn());
        self::assertStringStartsWith(
            bin2hex((string) new BeginRequest(FastCGI::RESPONDER, FastCGI::KEEP_CONN)),
            bin2hex((string) $request)
        );
    }

    /**
     * Deterministic, non-repeating content, so that a record cut at the wrong offset changes the bytes.
     */
    private static function body(int $length): string
    {
        $body = '';
        for ($i = 0; strlen($body) < $length; $i++) {
            $body .= md5((string) $i, true);
        }
        return substr($body, 0, $length);
    }

    private static function stdinRecord(string $content): string
    {
        $contentLength = strlen($content);
        $paddingLength = (8 - $contentLength % 8) % 8;

        return pack('CCnnCC', FastCGI::VERSION_1, FastCGI::STDIN, FastCGI::DEFAULT_REQUEST_ID, $contentLength, $paddingLength, 0)
            . $content
            . str_repeat("\0", $paddingLength);
    }
}
