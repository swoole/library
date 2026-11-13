<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Kafka;

use Swoole\Coroutine;
use Swoole\Kafka\Client\ClientInterface;
use Swoole\Kafka\Client\SwooleClient;
use Swoole\Kafka\Client\SyncClient;
use Swoole\Kafka\Config\CommonConfig;
use Swoole\Kafka\Exception\KafkaErrorException;
use Swoole\Kafka\Exception\SocketException;
use Swoole\Kafka\Protocol\ApiKeys;
use Swoole\Kafka\Protocol\ApiVersions\ApiVersionsRequest;
use Swoole\Kafka\Protocol\ApiVersions\ApiVersionsResponseKey;
use Swoole\Kafka\Protocol\ErrorCode;
use Swoole\Kafka\Protocol\Heartbeat\HeartbeatRequest;
use Swoole\Kafka\Protocol\Heartbeat\HeartbeatResponse;
use Swoole\Kafka\Socket\SocketInterface;
use Swoole\Kafka\Util\KafkaUtil;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Kafka\Client\SyncClient
 * @covers \Swoole\Kafka\KafkaUtil
 */
class RetryTest extends TestCase
{
    public function testRetryReconnectsOnceForACoordinatorError(): void
    {
        $failed = (new HeartbeatResponse())->setErrorCode(ErrorCode::NOT_COORDINATOR);
        $ok     = (new HeartbeatResponse())->setErrorCode(ErrorCode::NONE);
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->exactly(2))->method('sendRecv')->willReturnOnConsecutiveCalls($failed, $ok);
        $client->expects($this->once())->method('close');
        $client->expects($this->once())->method('connect');
        $client->expects($this->never())->method('downgradeApiVersion');

        $result = KafkaUtil::retry($client, new HeartbeatRequest(), 1);

        $this->assertSame(ErrorCode::NONE, $result->getErrorCode());
    }

    public function testUnsupportedVersionLowersTheApiVersionWithoutDroppingTheConnection(): void
    {
        $failed = (new HeartbeatResponse())->setErrorCode(ErrorCode::UNSUPPORTED_VERSION);
        $ok     = (new HeartbeatResponse())->setErrorCode(ErrorCode::NONE);
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->exactly(2))->method('sendRecv')->willReturnOnConsecutiveCalls($failed, $ok);
        $client->expects($this->once())->method('downgradeApiVersion')->with(ApiKeys::PROTOCOL_HEARTBEAT)->willReturn(true);
        $client->expects($this->never())->method('close');

        $result = KafkaUtil::retry($client, new HeartbeatRequest(), 3);

        $this->assertSame(ErrorCode::NONE, $result->getErrorCode());
    }

    public function testUnsupportedVersionThrowsWhenNoLowerVersionRemains(): void
    {
        $failed = (new HeartbeatResponse())->setErrorCode(ErrorCode::UNSUPPORTED_VERSION);
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRecv')->willReturn($failed);
        $client->method('downgradeApiVersion')->willReturn(false);
        $client->expects($this->never())->method('close');

        $this->expectException(KafkaErrorException::class);
        KafkaUtil::retry($client, new HeartbeatRequest(), 3);
    }

    public function testApiVersionStepsDownOneVersionAtATime(): void
    {
        $client = new SyncClient('127.0.0.1', 1, new CommonConfig());
        $key    = new ApiVersionsResponseKey();
        $key->setApiKey(ApiKeys::PROTOCOL_HEARTBEAT);
        $key->setMinVersion(2);
        $key->setMaxVersion(4);
        $client->setApiKeys([$key]);
        $request = new HeartbeatRequest();

        $this->assertSame(4, $client->getRequestApiVersion($request));
        $this->assertTrue($client->downgradeApiVersion(ApiKeys::PROTOCOL_HEARTBEAT));
        $this->assertSame(3, $client->getRequestApiVersion($request));
        $this->assertTrue($client->downgradeApiVersion(ApiKeys::PROTOCOL_HEARTBEAT));
        $this->assertSame(2, $client->getRequestApiVersion($request));
        $this->assertFalse($client->downgradeApiVersion(ApiKeys::PROTOCOL_HEARTBEAT));
        $this->assertSame(2, $client->getRequestApiVersion($request));
    }

    public function testAClosedConnectionIsRetriedOnce(): void
    {
        $config = new CommonConfig();
        $client = new SyncClient('127.0.0.1', 1, $config, ClosedSocket::class);
        try {
            $client->sendRecv(new ApiVersionsRequest());
            $this->fail('a connection that stays closed must not succeed');
        } catch (SocketException $exception) {
            $this->assertSame('closed', $exception->getMessage());
        }
        /** @var ClosedSocket $socket */
        $socket = $client->getSocket();
        $this->assertSame(2, $socket->sends);
        $this->assertSame(1, $socket->connects);
    }

    public function testAShortFrameIsRejected(): void
    {
        $client = new class('127.0.0.1', 1, new CommonConfig(), ShortFrameSocket::class) extends SyncClient {
            public function read(): string
            {
                return $this->readFrame();
            }
        };
        $this->expectException(SocketException::class);
        $client->read();
    }

    public function testSwooleClientRejectsAShortFrame(): void
    {
        self::coRun(function (): void {
            $config = new CommonConfig();
            $config->setRecvTimeout(2);
            $client = new SwooleClient('127.0.0.1', 1, $config, ShortFrameSocket::class);
            try {
                $client->sendRecv(new ApiVersionsRequest());
                $this->fail('a short frame must not be parsed');
            } catch (SocketException $exception) {
                $this->assertSame('Connection closed', $exception->getMessage());
            }
        });
    }

    public function testSwooleClientReportsASocketErrorOnce(): void
    {
        self::coRun(function (): void {
            $calls  = 0;
            $config = new CommonConfig();
            $config->setRecvTimeout(2);
            $config->setExceptionCallback(static function () use (&$calls): void {
                $calls++;
            });
            $client = new SwooleClient('127.0.0.1', 1, $config, ErrorSocket::class);
            try {
                $client->sendRecv(new ApiVersionsRequest());
                $this->fail('a broken socket must not return a response');
            } catch (\RuntimeException $exception) {
                $this->assertSame('boom', $exception->getMessage());
            }
            Coroutine::sleep(0.05);
            $this->assertSame(1, $calls);
        });
    }
}

class ClosedSocket implements SocketInterface
{
    public int $sends = 0;

    public int $connects = 0;

    private CommonConfig $config;

    public function __construct(string $host, int $port, ?CommonConfig $config = null)
    {
        $this->config = $config ?? new CommonConfig();
    }

    public function getHost(): string
    {
        return '127.0.0.1';
    }

    public function getPort(): int
    {
        return 1;
    }

    public function getConfig(): CommonConfig
    {
        return $this->config;
    }

    public function isConnected(): bool
    {
        return $this->connects > 0;
    }

    public function connect(): void
    {
        $this->connects++;
    }

    public function close(): bool
    {
        return true;
    }

    public function send(string $data, ?float $timeout = null): int
    {
        $this->sends++;
        throw new SocketException('closed');
    }

    public function recv(int $length, ?float $timeout = null): string
    {
        return '';
    }
}

class ShortFrameSocket implements SocketInterface
{
    private CommonConfig $config;

    public function __construct(string $host, int $port, ?CommonConfig $config = null)
    {
        $this->config = $config ?? new CommonConfig();
    }

    public function getHost(): string
    {
        return '127.0.0.1';
    }

    public function getPort(): int
    {
        return 1;
    }

    public function getConfig(): CommonConfig
    {
        return $this->config;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function connect(): void
    {
    }

    public function close(): bool
    {
        return true;
    }

    public function send(string $data, ?float $timeout = null): int
    {
        return \strlen($data);
    }

    public function recv(int $length, ?float $timeout = null): string
    {
        return 'abc';
    }
}

class ErrorSocket implements SocketInterface
{
    private CommonConfig $config;

    public function __construct(string $host, int $port, ?CommonConfig $config = null)
    {
        $this->config = $config ?? new CommonConfig();
    }

    public function getHost(): string
    {
        return '127.0.0.1';
    }

    public function getPort(): int
    {
        return 1;
    }

    public function getConfig(): CommonConfig
    {
        return $this->config;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function connect(): void
    {
    }

    public function close(): bool
    {
        return true;
    }

    public function send(string $data, ?float $timeout = null): int
    {
        return \strlen($data);
    }

    public function recv(int $length, ?float $timeout = null): string
    {
        throw new \RuntimeException('boom');
    }
}
