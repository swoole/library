<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Consumer\Assignor;

use Swoole\Kafka\Consumer\Struct\ConsumerGroupMemberMetadata;
use Swoole\Kafka\Group\Struct\ConsumerGroupMemberAssignment;
use Swoole\Kafka\Group\Struct\ConsumerGroupTopic;
use Swoole\Kafka\Protocol\JoinGroup\JoinGroupResponseMember;
use Swoole\Kafka\Protocol\Metadata\MetadataResponseTopic;
use Swoole\Kafka\Protocol\SyncGroup\SyncGroupRequestAssignment;

class RangeAssignor extends AbstractPartitionAssignor
{
    /**
     * @param MetadataResponseTopic[] $topicMetadatas
     * @param JoinGroupResponseMember[] $groupMembers
     *
     * @return SyncGroupRequestAssignment[]
     */
    public function assign(array $topicMetadatas, array $groupMembers): array
    {
        $partitions   = [];
        $memberTopics = [];
        $topicMembers = [];
        foreach ($groupMembers as $groupMember) {
            $memberId                    = $groupMember->getMemberId();
            $consumerGroupMemberMetadata = new ConsumerGroupMemberMetadata();
            $consumerGroupMemberMetadata->unpack($groupMember->getMetadata());
            $memberTopics[$memberId] = $topics = $consumerGroupMemberMetadata->getTopics();
            foreach ($topics as $topic) {
                if (!isset($partitions[$topic])) {
                    $partitions[$topic] = $this->getTopicPartitions($topic, $topicMetadatas);
                    sort($partitions[$topic]);
                }
                $topicMembers[$topic][$memberId] = [];
            }
        }
        /** @var SyncGroupRequestAssignment[] $assignments */
        $assignments = [];
        /** @var ConsumerGroupMemberAssignment[] $consumerGroupMemberAssignments */
        $consumerGroupMemberAssignments = [];
        foreach ($memberTopics as $memberId => $topics) {
            $assignments[] = $assignment = new SyncGroupRequestAssignment();
            $assignment->setMemberId($memberId);
            $consumerGroupMemberAssignments[] = $assignment = new ConsumerGroupMemberAssignment();
            foreach ($topics as $topic) {
                $topicMembers[$topic][$memberId]['assignment'] = $assignment;
            }
        }

        foreach ($partitions as $topicName => $topicPartitions) {
            $memberIds                   = array_keys($topicMembers[$topicName]);
            $memberCount                 = \count($memberIds);
            $partitionCount              = \count($topicPartitions);
            $numPartitionsPerConsumer    = (int) ($partitionCount / $memberCount);
            $consumersWithExtraPartition = $partitionCount % $memberCount;

            for ($i = 0; $i < $memberCount; $i++) {
                $start  = $numPartitionsPerConsumer * $i + min($i, $consumersWithExtraPartition);
                $length = $numPartitionsPerConsumer + ($i + 1 > $consumersWithExtraPartition ? 0 : 1);

                /** @var ConsumerGroupMemberAssignment $consumerGroupMemberAssignment */
                $consumerGroupMemberAssignment = $topicMembers[$topicName][$memberIds[$i]]['assignment'];
                $consumerGroupTopic            = new ConsumerGroupTopic();
                $consumerGroupTopic->setTopicName($topicName);
                $consumerGroupTopic->setPartitions(\array_slice($topicPartitions, $start, $length));
                $topics   = $consumerGroupMemberAssignment->getTopics();
                $topics[] = $consumerGroupTopic;
                $consumerGroupMemberAssignment->setTopics($topics);
            }
        }

        foreach ($assignments as $i => $assignment) {
            $assignment->setAssignment($consumerGroupMemberAssignments[$i]->pack());
        }

        return $assignments;
    }
}
