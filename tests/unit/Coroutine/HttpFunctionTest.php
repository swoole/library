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

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;
use Swoole\Constant;
use Swoole\Coroutine;

use function Swoole\Coroutine\Http\get;
use function Swoole\Coroutine\Http\post;

/**
 * @internal
 * @coversNothing
 */
#[CoversFunction('Swoole\Coroutine\Http\get')]
#[CoversFunction('Swoole\Coroutine\Http\post')]
class HttpFunctionTest extends TestCase
{
    public function testGet(): void
    {
        run(function () {
            Coroutine::create(function () {
                $this->fun1();
            });

            Coroutine::create(function () {
                $this->fun2();
            });
        });
    }

    public function testPost(): void
    {
        run(function () {
            $this->fun3();
        });
    }

    public function testCurlGet(): void
    {
        swoole_library_set_option(Constant::OPTION_HTTP_CLIENT_DRIVER, 'curl');
        $this->fun1();
        $this->fun2();
    }

    public function testCurlPost(): void
    {
        swoole_library_set_option(Constant::OPTION_HTTP_CLIENT_DRIVER, 'curl');
        $this->fun3();
    }

    public function testStreamGet(): void
    {
        swoole_library_set_option(Constant::OPTION_HTTP_CLIENT_DRIVER, 'stream');
        $this->fun1();
        $this->fun2();
    }

    public function testStreamPost(): void
    {
        swoole_library_set_option(Constant::OPTION_HTTP_CLIENT_DRIVER, 'stream');
        $this->fun3();
    }

    private function fun1(): void
    {
        self::assertSame(200, get('http://httpbin.org')->getStatusCode(), 'Test HTTP GET without query strings.');
    }

    private function fun2(): void
    {
        $data = get('http://httpbin.org/get?hello=world');
        $body = json_decode($data->getBody(), null, 512, JSON_THROW_ON_ERROR);
        self::assertSame('httpbin.org', $body->headers->Host);
        self::assertSame('world', $body->args->hello);
    }

    private function fun3(): void
    {
        $random_data = base64_encode(random_bytes(128));
        $data        = post('http://httpbin.org/post?hello=world', ['random_data' => $random_data]);
        $body        = json_decode($data->getBody(), null, 512, JSON_THROW_ON_ERROR);
        self::assertSame('httpbin.org', $body->headers->Host);
        self::assertSame('world', $body->args->hello);
        self::assertSame($random_data, $body->form->random_data);
    }
}
