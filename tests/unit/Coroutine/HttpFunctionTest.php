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

use Swoole\Constant;
use Swoole\Coroutine;
use Swoole\Tests\TestCase;

use function Swoole\Coroutine\Http\get;
use function Swoole\Coroutine\Http\post;

/**
 * @internal
 * @covers \Swoole\Coroutine\Http\get
 * @covers \Swoole\Coroutine\Http\post
 */
class HttpFunctionTest extends TestCase
{
    public function testGet(): void
    {
        self::coRun(function () {
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
        self::coRun(function () {
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
        self::assertSame(200, get(HTTPBIN_SERVER_URL)->getStatusCode(), 'Test HTTP GET without query strings.');
    }

    private function fun2(): void
    {
        $body = json_decode(
            get(HTTPBIN_SERVER_URL . '/get?hello=world')->getBody(),
            null,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(HTTPBIN_SERVER_HOST, $body->headers->Host[0]);
        self::assertSame('world', $body->args->hello[0]);
    }

    private function fun3(): void
    {
        $random_data = base64_encode(random_bytes(128));
        $body        = json_decode(
            post(HTTPBIN_SERVER_URL . '/post?hello=world', ['random_data' => $random_data])->getBody(),
            null,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(HTTPBIN_SERVER_HOST, $body->headers->Host[0]);
        self::assertSame('world', $body->args->hello[0]);
        self::assertSame($random_data, $body->form->random_data[0]);
    }
}
