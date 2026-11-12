<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Grpc;

use Etcdserverpb\KVClient;
use Etcdserverpb\PutRequest;
use Etcdserverpb\RangeRequest;
use Etcdserverpb\WatchClient;
use Etcdserverpb\WatchCreateRequest;
use Etcdserverpb\WatchRequest;
use Helloworld\GreeterClient;
use Helloworld\HelloRequest;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Grpc\Client;
use Swoole\Grpc\Parser;
use Swoole\Grpc\Request;
use Swoole\Tests\TestCase;

/**
 * End-to-end tests against real gRPC servers (a Python Helloworld.Greeter and etcd).
 *
 * They are gated behind GRPC_INTEGRATION=1 and a reachable endpoint, so the normal unit suite and
 * CI (which has neither service) skip them. To run locally:
 *
 *   docker compose -f tests/grpc/docker-compose.yml -p swoole-library-grpc up -d --build
 *   GRPC_INTEGRATION=1 php -d swoole.enable_library=Off ./vendor/bin/counit tests/unit/Grpc/IntegrationTest.php
 *
 * @internal
 * @coversNothing
 */
class IntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('GRPC_INTEGRATION')) {
            $this->markTestSkipped('Set GRPC_INTEGRATION=1 and start tests/grpc/docker-compose.yml to run gRPC integration tests.');
        }
    }

    public function testGreeterUnaryCall(): void
    {
        self::requireEndpoint(self::greeterHost(), 50051);
        self::coRun(function (): void {
            $client  = new GreeterClient('127.0.0.1:50051');
            $request = new HelloRequest();
            $request->setName('Swoole');
            [$reply, $status, $response] = $client->SayHello($request);
            $this->assertSame(0, $status);
            $this->assertSame('Hello Swoole', $reply->getMessage());
            $this->assertSame(200, $response->statusCode);
            $this->assertSame('application/grpc', $response->headers['content-type']);
            $this->assertTrue($client->isConnected());
            $client->close();
        });
    }

    public function testGreeterUnaryWithMetadata(): void
    {
        self::requireEndpoint(self::greeterHost(), 50051);
        self::coRun(function (): void {
            $client  = new GreeterClient('127.0.0.1:50051');
            $request = new HelloRequest();
            $request->setName('Metadata');
            [$reply, $status] = $client->SayHello($request, ['x-request-id' => 'abc123']);
            $this->assertSame(0, $status);
            $this->assertSame('Hello Metadata', $reply->getMessage());
            $client->close();
        });
    }

    public function testLargeMessageAcrossDataFrames(): void
    {
        self::requireEndpoint(self::greeterHost(), 50051);
        self::coRun(function (): void {
            $client  = new GreeterClient('127.0.0.1:50051');
            $request = new HelloRequest();
            $request->setName('LARGE:');
            // A 64 KiB reply is split across several HTTP/2 DATA frames; the client must hand
            // back the complete payload.
            [$reply, $status] = $client->SayHello($request);
            $this->assertSame(0, $status);
            $this->assertStringStartsWith('LARGE:', $reply->getMessage());
            $this->assertSame(6 + 64 * 1024, strlen($reply->getMessage()));
            $client->close();
        });
    }

    public function testLargeMessageViaInvokeUnaryResult(): void
    {
        self::requireEndpoint(self::greeterHost(), 50051);
        self::coRun(function (): void {
            $client  = new GreeterClient('127.0.0.1:50051');
            $request = new HelloRequest();
            $request->setName('LARGE:');
            $result = $client->invokeUnary('/helloworld.Greeter/SayHello', $request, [\Helloworld\HelloReply::class, 'decode']);
            $this->assertTrue($result->isOk());
            $this->assertSame(6 + 64 * 1024, strlen($result->getReply()->getMessage()));
            $client->close();
        });
    }

    public function testConcurrentUnaryCallsShareOneConnection(): void
    {
        self::requireEndpoint(self::greeterHost(), 50051);
        self::coRun(function (): void {
            $client  = new GreeterClient('127.0.0.1:50051');
            $channel = new Channel(6);
            for ($i = 0; $i < 6; $i++) {
                Coroutine::create(function () use ($client, $channel, $i): void {
                    $request = new HelloRequest();
                    $request->setName("N{$i}");
                    [$reply, $status] = $client->SayHello($request);
                    $channel->push([$i, $status, $reply ? $reply->getMessage() : null]);
                });
            }
            $ok = 0;
            for ($i = 0; $i < 6; $i++) {
                $result = $channel->pop(5.0);
                if ($result && $result[1] === 0 && $result[2] === "Hello N{$result[0]}") {
                    $ok++;
                }
            }
            $this->assertSame(6, $ok);
            $client->close();
        });
    }

    public function testEtcdPutAndRange(): void
    {
        self::requireEndpoint(self::etcdHost(), 2379);
        self::coRun(function (): void {
            $key    = 'grpc_test_' . bin2hex(random_bytes(4));
            $client = new KVClient('127.0.0.1:2379');

            $put = new PutRequest();
            $put->setKey($key);
            $put->setValue('swoole-value');
            [$putReply, $putStatus] = $client->Put($put);
            $this->assertSame(0, $putStatus);
            $this->assertInstanceOf(\Etcdserverpb\PutResponse::class, $putReply);

            $range = new RangeRequest();
            $range->setKey($key);
            [$rangeReply, $rangeStatus] = $client->Range($range);
            $this->assertSame(0, $rangeStatus);
            $this->assertSame(1, $rangeReply->getCount());
            $kvs = $rangeReply->getKvs();
            $this->assertCount(1, $kvs);
            $this->assertSame('swoole-value', $kvs[0]->getValue());
            $client->close();
        });
    }

    public function testEtcdWatchBidiStream(): void
    {
        self::requireEndpoint(self::etcdHost(), 2379);
        self::coRun(function (): void {
            $client = new WatchClient('127.0.0.1:2379');
            $call   = $client->Watch();

            $create = new WatchCreateRequest();
            $create->setKey('grpc_watch_' . bin2hex(random_bytes(4)));
            $request = new WatchRequest();
            $request->setCreateRequest($create);
            $this->assertTrue($call->push($request));

            [$reply, $status] = $call->recv(3.0);
            $this->assertSame(0, $status);
            $this->assertInstanceOf(\Etcdserverpb\WatchResponse::class, $reply);
            $this->assertTrue($reply->getCreated());

            $this->assertTrue($call->end());
            $client->close();
        });
    }

    public function testGrpcErrorStatusIsParsed(): void
    {
        self::requireEndpoint(self::etcdHost(), 2379);
        self::coRun(function (): void {
            $client           = new Client('127.0.0.1:2379');
            $request          = new Request();
            $request->method  = 'POST';
            $request->path    = '/nope.Service/Method';
            $request->data    = Parser::pack('payload');
            $streamId         = $client->send($request);
            $response         = $client->recv($streamId, 3.0);
            [$reply, $status] = Parser::parseToResultArray($response, static fn (string $v) => $v);
            $this->assertNotSame(0, $status);
            $this->assertIsString($reply);
            $this->assertNotEmpty($reply);
            $client->close();
        });
    }

    public function testSharedClientAcrossGeneratedClients(): void
    {
        self::requireEndpoint(self::etcdHost(), 2379);
        self::coRun(function (): void {
            // The ['use' => $client] option lets different generated stubs share one connection.
            $shared = new Client('127.0.0.1:2379');
            $kv     = new KVClient('127.0.0.1:2379', ['use' => $shared]);

            $put = new PutRequest();
            $put->setKey('grpc_shared_' . bin2hex(random_bytes(4)));
            $put->setValue('shared');
            [, $status] = $kv->Put($put);
            $this->assertSame(0, $status);
            $this->assertTrue($kv->isConnected());
            $shared->close();
        });
    }

    private static function greeterHost(): string
    {
        return getenv('GRPC_GREETER_HOST') ?: '127.0.0.1';
    }

    private static function etcdHost(): string
    {
        return getenv('GRPC_ETCD_HOST') ?: '127.0.0.1';
    }

    private static function requireEndpoint(string $host, int $port): void
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($socket === false) {
            self::markTestSkipped("gRPC integration endpoint {$host}:{$port} is not reachable ({$errstr}).");
        }
        fclose($socket);
    }
}
