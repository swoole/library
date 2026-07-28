<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine\Http2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Server;
use Swoole\Coroutine\WaitGroup;
use Swoole\Http2\Request;
use Swoole\Http2\Response;

use function Swoole\Coroutine\go;
use function Swoole\Coroutine\run;

/**
 * @internal
 */
#[CoversClass(MultiplexClient::class)]
class MultiplexClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(Client::class, false)) {
            self::markTestSkipped('Swoole is compiled without HTTP/2 support (--enable-http2).');
        }
    }

    public function testRequest(): void
    {
        run(function () {
            $server = new Server('127.0.0.1', 0);
            $server->set(['open_http2_protocol' => true]);
            go(function () use ($server) {
                $server->handle('/', function ($request, $response) {
                    $response->end($request->getContent());
                });
                $server->start();
            });

            $client = new MultiplexClient('127.0.0.1', $server->port);
            $client->set(['timeout' => 5]);
            $this->assertTrue($client->connect());

            $wg = new WaitGroup();
            for ($i = 1; $i <= 30; $i++) {
                $wg->add();
                go(function () use ($client, $wg, $i) {
                    try {
                        $request         = new Request();
                        $request->method = 'POST';
                        $request->path   = '/';
                        $request->data   = "payload-{$i}";

                        $response = $client->request($request, 10);
                        $this->assertInstanceOf(Response::class, $response);
                        $this->assertSame(200, $response->statusCode);
                        $this->assertSame("payload-{$i}", $response->data);
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();

            $client->close();
            $server->shutdown();
        });
    }
}
