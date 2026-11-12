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

use Swoole\Coroutine\Http2\Client as Http2Client;
use Swoole\Grpc\Client;
use Swoole\Grpc\Request;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Client
 */
class ClientTest extends TestCase
{
    public function testDefaultTimeoutConstant(): void
    {
        $this->assertSame(-1.0, Client::DEFAULT_TIMEOUT);
    }

    public function testIllegalHostnameThrows(): void
    {
        foreach (['', 'not-a-host', 'http://example.com'] as $hostname) {
            try {
                new Client($hostname);
                $this->fail("Expected InvalidArgumentException for '{$hostname}'");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('illegal', $e->getMessage());
            }
        }
    }

    public function testTimeoutAccessors(): void
    {
        $client = self::newUnstartedClient();
        $this->assertSame(Client::DEFAULT_TIMEOUT, $client->getTimeout());
        $client->setTimeout(2.5);
        $this->assertSame(2.5, $client->getTimeout());
    }

    public function testNotRunningBeforeStart(): void
    {
        $this->assertFalse(self::newUnstartedClient()->isRunning());
    }

    public function testStreamDoesNotExistBeforeAnySend(): void
    {
        $this->assertFalse(self::newUnstartedClient()->isStreamExist(1));
    }

    public function testNumStatsShape(): void
    {
        $stats = Client::numStats();
        $this->assertArrayHasKey('constructed_num', $stats);
        $this->assertArrayHasKey('destructed_num', $stats);
        $this->assertIsInt($stats['constructed_num']);
        $this->assertIsInt($stats['destructed_num']);
    }

    public function testDebugToggleDoesNotThrow(): void
    {
        Client::debug(true);
        Client::debug(false);
        $this->assertTrue(true);
    }

    public function testOpenStreamInfersGetWithoutData(): void
    {
        $client = new RecordingClient();
        $this->assertSame(7, $client->openStream('/helloworld.Greeter/SayHello'));
        $this->assertSame('GET', $client->lastRequest->method);
        $this->assertSame('/helloworld.Greeter/SayHello', $client->lastRequest->path);
        $this->assertTrue($client->lastRequest->pipeline);
    }

    public function testOpenStreamInfersPostWithData(): void
    {
        $client = new RecordingClient();
        $client->openStream('/m', 'payload-bytes');
        $this->assertSame('POST', $client->lastRequest->method);
        $this->assertSame('payload-bytes', $client->lastRequest->data);
    }

    public function testOpenStreamHonoursExplicitMethod(): void
    {
        $client = new RecordingClient();
        $client->openStream('/m', '', 'PUT');
        $this->assertSame('PUT', $client->lastRequest->method);
    }

    public function testOpenStreamPipelineReadAndHeaders(): void
    {
        $client = new RecordingClient();
        $client->openStream('/m', '', 'POST', true, ['authorization' => 'Bearer x']);
        $this->assertTrue($client->lastRequest->usePipelineRead);
        $this->assertSame('Bearer x', $client->lastRequest->headers['authorization']);
        $this->assertSame('application/grpc', $client->lastRequest->headers['content-type']);
    }

    public function testOpenStreamWithoutPipelineRead(): void
    {
        $client = new RecordingClient();
        $client->openStream('/m', 'data');
        $this->assertFalse((bool) ($client->lastRequest->usePipelineRead ?? false));
    }

    private static function newUnstartedClient(): Client
    {
        return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }
}

/**
 * A Client that records the Request passed to send() instead of touching the network.
 */
class RecordingClient extends Client
{
    public ?Request $lastRequest = null;

    public function __construct()
    {
        // Give close()/stats() a harmless underlying client without connecting.
        $this->client = new Http2Client('127.0.0.1', 1, false);
    }

    public function send(Request $request): int
    {
        $this->lastRequest = $request;
        return 7;
    }
}
