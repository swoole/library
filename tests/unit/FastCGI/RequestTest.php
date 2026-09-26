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
     * A body of "0" is a body: it used to be dropped because it is "empty" to PHP.
     */
    public function testBodyOfASingleZero(): void
    {
        $request = (new Request())->withBody('0');

        $expected = (string) new BeginRequest(FastCGI::RESPONDER, 0) . new Params([]) . new Params([])
            . self::stdinRecord('0') . self::stdinRecord('');

        self::assertSame(bin2hex($expected), bin2hex((string) $request));
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
