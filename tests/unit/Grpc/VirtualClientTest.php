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
use Swoole\Grpc\Request;
use Swoole\Grpc\VirtualClient;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\VirtualClient
 */
class VirtualClientTest extends TestCase
{
    public function testUseInjectedClient(): void
    {
        $fake = new VirtualFakeClient();
        $vc   = new VirtualClient('127.0.0.1:50051', ['use' => $fake]);
        $this->assertSame($fake, $vc->getGrpcClient());
    }

    public function testRejectsInvalidUseOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VirtualClient('127.0.0.1:50051', ['use' => new \stdClass()]);
    }

    public function testUseReplacesClient(): void
    {
        $first  = new VirtualFakeClient();
        $second = new VirtualFakeClient();
        $vc     = new VirtualClient('127.0.0.1:50051', ['use' => $first]);
        $vc->use($second);
        $this->assertSame($second, $vc->getGrpcClient());
    }

    public function testDelegatesSetTimeout(): void
    {
        $fake = new VirtualFakeClient();
        $vc   = new VirtualClient('127.0.0.1:50051', ['use' => $fake]);
        $vc->setTimeout(3.5);
        $this->assertSame(3.5, $fake->timeout);
    }

    public function testDelegatesGetTimeout(): void
    {
        $fake          = new VirtualFakeClient();
        $fake->timeout = 8.0;
        $vc            = new VirtualClient('127.0.0.1:50051', ['use' => $fake]);
        $this->assertSame(8.0, $vc->getTimeout());
    }

    public function testDelegatesIsConnected(): void
    {
        $fake            = new VirtualFakeClient();
        $fake->connected = true;
        $vc              = new VirtualClient('127.0.0.1:50051', ['use' => $fake]);
        $this->assertTrue($vc->isConnected());
    }

    public function testDelegatesOpenStream(): void
    {
        $fake = new VirtualFakeClient();
        $vc   = new VirtualClient('127.0.0.1:50051', ['use' => $fake]);
        $this->assertSame(42, $vc->openStream('/m', 'data', 'POST', true, ['x' => 'y']));
        $this->assertSame('/m', $fake->lastOpenPath);
        $this->assertSame(['x' => 'y'], $fake->lastHeaders);
    }

    public function testDelegatesSend(): void
    {
        $fake    = new VirtualFakeClient();
        $vc      = new VirtualClient('127.0.0.1:50051', ['use' => $fake]);
        $request = new Request();
        $this->assertSame(11, $vc->send($request));
        $this->assertSame($request, $fake->lastRequest);
    }

    public function testDelegatesClose(): void
    {
        $fake = new VirtualFakeClient();
        $vc   = new VirtualClient('127.0.0.1:50051', ['use' => $fake]);
        $vc->close();
        $this->assertTrue($fake->closed);
    }

    public function testStaticNumStatsDelegatesToClient(): void
    {
        $this->assertSame(Client::numStats(), VirtualClient::numStats());
    }

    public function testDelegationWithoutClientThrows(): void
    {
        $vc = (new \ReflectionClass(VirtualClient::class))->newInstanceWithoutConstructor();
        $this->expectException(\RuntimeException::class);
        $vc->isConnected();
    }
}

/** @internal */
class VirtualFakeClient extends Client
{
    public float $timeout = 1.0;

    public bool $connected = false;

    public bool $closed = false;

    public ?string $lastOpenPath = null;

    public array $lastHeaders = [];

    public ?Request $lastRequest = null;

    public function __construct()
    {
        // Avoid connecting; every method used below is overridden.
    }

    public function setTimeout(float $timeout): void
    {
        $this->timeout = $timeout;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function openStream(
        string $path,
        ?string $data = '',
        string $method = '',
        bool $usePipelineRead = false,
        array $headers = [],
    ): int {
        $this->lastOpenPath = $path;
        $this->lastHeaders  = $headers;
        return 42;
    }

    public function send(Request $request): int
    {
        $this->lastRequest = $request;
        return 11;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
