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
use Swoole\Kafka\Client\SwooleClient;
use Swoole\Kafka\Client\SyncClient;
use Swoole\Kafka\Config\CommonConfig;
use Swoole\Kafka\Consumer\Assignor\RangeAssignor;
use Swoole\Kafka\Consumer\Assignor\RoundRobinAssignor;
use Swoole\Kafka\Consumer\Assignor\StickyAssignor;
use Swoole\Kafka\Consumer\ConsumeMessage;
use Swoole\Kafka\Consumer\Consumer;
use Swoole\Kafka\Consumer\ConsumerConfig;
use Swoole\Kafka\Producer\Producer;
use Swoole\Kafka\Producer\ProducerConfig;
use Swoole\Kafka\Socket\StreamSocket;
use Swoole\Tests\TestCase;

/**
 * Runs against the kafka service. Serial: the tests share that broker, and each one starts its own coroutine scheduler.
 *
 * @internal
 * @covers \Swoole\Kafka\Consumer\Consumer
 * @covers \Swoole\Kafka\Producer\Producer
 */
class BrokerTest extends TestCase
{
    public function testSyncClientReadsApiVersions(): void
    {
        $this->inCoroutine(function (): void {
            $client = new SyncClient(KAFKA_SERVER_HOST, KAFKA_SERVER_PORT, $this->clientConfig(), StreamSocket::class);
            $client->connect();
            try {
                $this->assertNotEmpty($client->getApiKeys());
            } finally {
                $this->assertTrue($client->close());
            }
        });
    }

    public function testSwooleClientReadsApiVersions(): void
    {
        $this->inCoroutine(function (): void {
            $client = new SwooleClient(KAFKA_SERVER_HOST, KAFKA_SERVER_PORT, $this->clientConfig());
            $client->connect();
            try {
                $this->assertNotEmpty($client->getApiKeys());
            } finally {
                $this->assertTrue($client->close());
            }
        });
    }

    public function testRangeAssignorReadsWhatWasProduced(): void
    {
        $this->inCoroutine(function (): void {
            $this->assertRoundTrip(RangeAssignor::class);
        });
    }

    public function testRoundRobinAssignorReadsWhatWasProduced(): void
    {
        $this->inCoroutine(function (): void {
            $this->assertRoundTrip(RoundRobinAssignor::class);
        });
    }

    public function testStickyAssignorReadsWhatWasProduced(): void
    {
        $this->inCoroutine(function (): void {
            $this->assertRoundTrip(StickyAssignor::class);
        });
    }

    private function assertRoundTrip(string $assignor): void
    {
        $topic = 'library-' . bin2hex(random_bytes(4));
        $value = 'value-' . $topic;
        $this->produce($topic, $value);

        $config = new ConsumerConfig();
        $config->setBroker(KAFKA_SERVER_HOST . ':' . KAFKA_SERVER_PORT);
        $config->setTopic($topic);
        $config->setGroupId($topic);
        $config->setClientId('library-test');
        $config->setPartitionAssignmentStrategy($assignor);
        $config->setInterval(0.05);
        $config->setMaxWait(200);
        $config->setConnectTimeout(5);
        $config->setSendTimeout(5);
        $config->setRecvTimeout(5);
        $config->setGroupRetry(10);
        $config->setGroupRetrySleep(0.2);
        $consumer = new Consumer($config, static function (): void {
        });
        try {
            $found    = null;
            $deadline = microtime(true) + 20;
            while ($found === null && microtime(true) < $deadline) {
                $message = $consumer->consume();
                if ($message instanceof ConsumeMessage && $message->getValue() === $value) {
                    $found = $message;
                }
            }
        } finally {
            $consumer->close();
        }

        $this->assertInstanceOf(ConsumeMessage::class, $found);
        $this->assertSame($value, $found->getValue());
        $this->assertSame('key', $found->getKey());
        $this->assertSame($topic, $found->getTopic());
        $headers = $found->getHeaders();
        $this->assertCount(1, $headers);
        $this->assertSame('h', $headers[0]->getHeaderKey());
        $this->assertSame('v', $headers[0]->getValue());
    }

    private function produce(string $topic, string $value): void
    {
        $config = new ProducerConfig();
        $config->setBootstrapServer(KAFKA_SERVER_HOST . ':' . KAFKA_SERVER_PORT);
        $config->setAcks(-1);
        $config->setProduceRetry(20);
        $config->setProduceRetrySleep(0.2);
        $config->setConnectTimeout(5);
        $config->setSendTimeout(5);
        $config->setRecvTimeout(5);
        $producer = new Producer($config);
        try {
            $producer->send($topic, $value, 'key', ['h' => 'v'], 0);
        } finally {
            $producer->close();
        }
    }

    /**
     * Coroutine::run() does not rethrow what the callback throws, so keep the failure for PHPUnit.
     */
    private function inCoroutine(callable $callback): void
    {
        $error = null;
        Coroutine\run(static function () use ($callback, &$error): void {
            try {
                $callback();
            } catch (\Throwable $throwable) {
                $error = $throwable;
            }
        });
        if ($error instanceof \Throwable) {
            throw $error;
        }
    }

    private function clientConfig(): CommonConfig
    {
        $config = new CommonConfig();
        $config->setConnectTimeout(5);
        $config->setSendTimeout(5);
        $config->setRecvTimeout(5);

        return $config;
    }
}
