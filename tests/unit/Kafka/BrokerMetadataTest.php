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

use Swoole\Kafka\Broker;
use Swoole\Kafka\Client\ClientInterface;
use Swoole\Kafka\Consumer\ConsumerConfig;
use Swoole\Kafka\Protocol\ErrorCode;
use Swoole\Kafka\Protocol\Metadata\MetadataResponse;
use Swoole\Kafka\Protocol\Metadata\MetadataResponseTopic;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Kafka\Broker
 */
class BrokerMetadataTest extends TestCase
{
    public function testRefreshingATopicReplacesThePreviousCopy(): void
    {
        $orders   = $this->topic('orders');
        $payments = $this->topic('payments');
        $client   = $this->createMock(ClientInterface::class);
        $client->method('sendRecv')->willReturnOnConsecutiveCalls(
            (new MetadataResponse())->setTopics([$orders]),
            (new MetadataResponse())->setTopics([$payments]),
            (new MetadataResponse())->setTopics([$this->topic('orders')]),
        );
        $broker = new Broker(new ConsumerConfig());

        $broker->updateMetadata(['orders'], $client);
        $broker->updateMetadata(['payments'], $client);
        $broker->updateMetadata(['orders'], $client);

        $names = [];
        foreach ($broker->getTopicsMeta() as $topic) {
            $names[] = $topic->getName();
        }
        $this->assertSame(['orders', 'payments'], $names);
    }

    private function topic(string $name): MetadataResponseTopic
    {
        return (new MetadataResponseTopic())->setName($name)->setErrorCode(ErrorCode::NONE)->setPartitions([]);
    }
}
