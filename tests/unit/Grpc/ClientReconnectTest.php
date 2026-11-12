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

use Swoole\Grpc\Client;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Client
 */
class ClientReconnectTest extends TestCase
{
    public function testDefaultsReproduceOriginalBehaviour(): void
    {
        $client = new NoStartClient('127.0.0.1:50051');
        $this->assertSame(1, self::prop($client, 'reconnectAttempts'));
        $this->assertSame(0.0, self::prop($client, 'keepaliveTime'));
        $this->assertCount(1, self::prop($client, 'endpoints'));
        $this->assertSame(0, self::prop($client, 'endpointIndex'));
        // Control keys are not forwarded to the underlying Http2 client.
        $clientOpts = self::prop($client, 'clientOpts');
        $this->assertArrayNotHasKey('keepalive_time', $clientOpts);
        $this->assertArrayNotHasKey('endpoints', $clientOpts);
        $this->assertArrayNotHasKey('reconnect_attempts', $clientOpts);
        // Transport keys are still forwarded.
        $this->assertArrayHasKey('timeout', $clientOpts);
        $this->assertArrayHasKey('ssl', $clientOpts);
    }

    public function testEndpointsAreParsedInOrder(): void
    {
        $client = new NoStartClient('127.0.0.1:50051', [
            'endpoints' => ['10.0.0.1:2379', '10.0.0.2:2379', 'unix:///tmp/grpc.sock'],
        ]);
        $endpoints = self::prop($client, 'endpoints');
        $this->assertCount(4, $endpoints);
        $this->assertSame(['host' => '127.0.0.1', 'port' => 50051], $endpoints[0]);
        $this->assertSame(['host' => '10.0.0.1', 'port' => 2379], $endpoints[1]);
        $this->assertSame(['host' => '10.0.0.2', 'port' => 2379], $endpoints[2]);
        $this->assertSame(['host' => 'unix:///tmp/grpc.sock', 'port' => 0], $endpoints[3]);
    }

    public function testInvalidEndpointsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NoStartClient('127.0.0.1:50051', [
            'endpoints' => ['bad-host-without-port', '10.0.0.1:2379'],
        ]);
    }

    public function testResilienceKnobs(): void
    {
        $client = new NoStartClient('127.0.0.1:50051', [
            'keepalive_time'            => 10.0,
            'reconnect_attempts'        => 5,
            'reconnect_backoff_initial' => 0.2,
            'reconnect_backoff_max'     => 2.0,
        ]);
        $this->assertSame(10.0, self::prop($client, 'keepaliveTime'));
        $this->assertSame(5, self::prop($client, 'reconnectAttempts'));
    }

    public function testTlsOptionsStillForwardedWithControlKeys(): void
    {
        $client = new NoStartClient('127.0.0.1:50051', [
            'ssl'            => true,
            'ssl_cafile'     => '/ca.pem',
            'keepalive_time' => 5.0,
        ]);
        $clientOpts = self::prop($client, 'clientOpts');
        $this->assertTrue($clientOpts['ssl']);
        $this->assertSame('/ca.pem', $clientOpts['ssl_cafile']);
        $this->assertArrayNotHasKey('keepalive_time', $clientOpts);
    }

    private static function prop(Client $client, string $name)
    {
        $property = new \ReflectionProperty(Client::class, $name);
        return $property->getValue($client);
    }
}

/** A Client that runs the real constructor but skips start() (no network in a unit test). */
class NoStartClient extends Client
{
    protected function start(): void
    {
        // Intentionally empty.
    }
}
