<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use function Swoole\Coroutine\Http\get;
use function Swoole\Coroutine\Http\post;

/**
 * @internal
 * @coversNothing
 */
class HttpFunctionTest extends TestCase
{
    public function testGet()
    {
        run(function () {
            Coroutine::create(function () {
                self::assertSame(200, get('http://httpbin.org')->getStatusCode(), 'Test HTTP GET without query strings.');
            });

            Coroutine::create(function () {
                $data = get('http://httpbin.org/get?hello=world');
                $body = json_decode($data->getBody());
                self::assertSame('httpbin.org', $body->headers->Host);
                self::assertSame('world', $body->args->hello);
            });
        });
    }

    public function testPost()
    {
        run(function () {
            $random_data = base64_encode(random_bytes(128));
            $data = post('http://httpbin.org/post?hello=world', ['random_data' => $random_data]);
            $body = json_decode($data->getBody());
            self::assertSame('httpbin.org', $body->headers->Host);
            self::assertSame('world', $body->args->hello);
            self::assertSame($random_data, $body->form->random_data);
        });
    }
}
