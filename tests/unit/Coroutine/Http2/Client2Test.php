<?php

/**
 * This file is part of Swoole.
 *
 * @see     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine\Http2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Http2\Request;

use function Swoole\Coroutine\run;

/**
 * @internal
 */
#[CoversClass(Client2::class)]
class Client2Test extends TestCase
{
    public function testRequest(): void
    {
        run(function () {
            $domain = 'httpbin.org';
            $client = new Client2($domain, 443, true);
            $client->set([
                'timeout' => -1,
                'ssl_host_name' => $domain,
            ]);
            $client->connect();
            for ($i = 1; $i < 30; ++$i) {
                Coroutine::create(function () use ($client, $i) {
                    $req = new Request();
                    $req->method = 'POST';
                    $req->path = '/post';
                    $req->headers = [
                        'host' => '127.0.0.1',
                        'user-agent' => 'Chrome/49.0.2587.3',
                        'accept' => 'text/html,application/xhtml+xml,application/xml',
                        'accept-encoding' => 'gzip',
                    ];
                    $req->data = (string) $i;
                    $data = $client->request($req);
                    $result = json_decode($data->data, true);
                    $this->assertEquals($i, intval($result['data']));
                });
            }
        });
    }
}
