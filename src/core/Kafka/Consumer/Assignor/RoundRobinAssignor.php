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

use Swoole\Kafka\Consumer\Assignor\Struct\TopicAndPartition;
use Swoole\Kafka\Consumer\Struct\ConsumerGroupMemberMetadata;
use Swoole\Kafka\Group\Struct\ConsumerGroupMemberAssignment;
use Swoole\Kafka\Group\Struct\ConsumerGroupTopic;
use Swoole\Kafka\Protocol\JoinGroup\JoinGroupResponseMember;
use Swoole\Kafka\Protocol\Metadata\MetadataResponseTopic;
use Swoole\Kafka\Protocol\SyncGroup\SyncGroupRequestAssignment;

class RoundRobinAssignor extends AbstractPartitionAssignor
{
    /**
     * @param MetadataResponseTopic[] $topicMetadatas
     * @param JoinGroupResponseMember[] $groupMembers
     *
     * @return SyncGroupRequestAssignment[]
     */
    public function assign(array $topicMetadatas, array $groupMembers): array
    {
        $memberIds      = [];
        $topicMemberIds = [];
        /** @var TopicAndPartition[] $topicAndPartitions */
        $topicAndPartitions = [];
        foreach ($groupMembers as $groupMember) {
            $memberId                    = $groupMember->getMemberId();
            $memberIds[]                 = $memberId;
            $consumerGroupMemberMetadata = new ConsumerGroupMemberMetadata();
            $consumerGroupMemberMetadata->unpack($groupMember->getMetadata());
            foreach ($consumerGroupMemberMetadata->getTopics() as $topic) {
                $topicMemberIds[$topic][] = $memberId;
            }
        }
        $topicIndexs = [];
        foreach ($topicMetadatas as $topicMetadata) {
            $topic = $topicMetadata->getName();
            if (!isset($topicMemberIds[$topic])) {
                continue;
            }
            if (!isset($topicIndexs[$topic])) {
                $topicIndexs[$topic] = 0;
                foreach ($this->getTopicPartitions($topic, $topicMetadatas) as $partition) {
                    $topicAndPartition                                       = new TopicAndPartition($topic, $partition);
                    $topicAndPartitions[spl_object_hash($topicAndPartition)] = $topicAndPartition;
                }
            }
        }
        ksort($topicAndPartitions);

        $assignments      = [];
        $memberPartitions = [];
        foreach ($memberIds as $memberId) {
            $assignments[] = $assignment = new SyncGroupRequestAssignment();
            $assignment->setMemberId($memberId);
            $memberPartitions[$memberId] = [];
        }

        foreach ($topicAndPartitions as $topicAndPartition) {
            $topic                                           = $topicAndPartition->getTopic();
            $memberCount                                     = \count($topicMemberIds[$topic]);
            $i                                               = &$topicIndexs[$topic];
            $memberPartitions[$topicMemberIds[$topic][$i]][] = $topicAndPartition;
            $i++;
            if ($i >= $memberCount) {
                $i = 0;
            }
            unset($i);
        }

        $i = 0;
        foreach ($memberPartitions as $memberId => $topicAndPartitions) {
            $consumerGroupMemberAssignment = new ConsumerGroupMemberAssignment();
            $consumerGroupTopics           = [];
            /** @var TopicAndPartition[] $topicAndPartitions */
            foreach ($topicAndPartitions as $topicAndPartition) {
                $topic = $topicAndPartition->getTopic();
                if (isset($consumerGroupTopics[$topic])) {
                    $consumerGroupTopic = $consumerGroupTopics[$topic];
                } else {
                    $consumerGroupTopic = $consumerGroupTopics[$topic] = new ConsumerGroupTopic();
                    $consumerGroupTopic->setTopicName((string) $topic);
                }
                $partitions   = $consumerGroupTopic->getPartitions();
                $partitions[] = $topicAndPartition->getPartition();
                $consumerGroupTopic->setPartitions($partitions);
            }
            $consumerGroupMemberAssignment->setTopics($consumerGroupTopics);
            $assignments[$i]->setAssignment($consumerGroupMemberAssignment->pack());
            $i++;
        }

        return $assignments;
    }
}
