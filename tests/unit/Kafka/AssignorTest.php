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

use Swoole\Kafka\Consumer\Assignor\RangeAssignor;
use Swoole\Kafka\Consumer\Assignor\RoundRobinAssignor;
use Swoole\Kafka\Consumer\Assignor\StickyAssignor;
use Swoole\Kafka\Consumer\Struct\ConsumerGroupMemberMetadata;
use Swoole\Kafka\Group\Struct\ConsumerGroupMemberAssignment;
use Swoole\Kafka\Protocol\JoinGroup\JoinGroupResponseMember;
use Swoole\Kafka\Protocol\Metadata\MetadataResponsePartition;
use Swoole\Kafka\Protocol\Metadata\MetadataResponseTopic;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Kafka\Consumer\Assignor\RangeAssignor
 * @covers \Swoole\Kafka\Consumer\Assignor\RoundRobinAssignor
 * @covers \Swoole\Kafka\Consumer\Assignor\StickyAssignor
 */
class AssignorTest extends TestCase
{
    /**
     * @dataProvider assignors
     */
    public function testNumericTopicNameStaysAString(string $assignorClass): void
    {
        $metadata = new ConsumerGroupMemberMetadata();
        $metadata->setTopics(['8']);
        $member = new JoinGroupResponseMember();
        $member->setMemberId('member-1');
        $member->setMetadata($metadata->pack());

        $topic = new MetadataResponseTopic();
        $topic->setName('8');
        $topic->setPartitions([
            (new MetadataResponsePartition())->setPartitionIndex(0),
            (new MetadataResponsePartition())->setPartitionIndex(1),
        ]);

        $assignor    = new $assignorClass();
        $assignments = $assignor->assign([$topic], [$member]);
        $this->assertNotEmpty($assignments);

        $decoded = new ConsumerGroupMemberAssignment();
        $decoded->unpack(array_values($assignments)[0]->getAssignment());
        $this->assertSame('8', $decoded->getTopics()[0]->getTopicName());
    }

    public static function assignors(): array
    {
        return [
            [RangeAssignor::class],
            [RoundRobinAssignor::class],
            [StickyAssignor::class],
        ];
    }
}
