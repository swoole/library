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

use Swoole\Kafka\Protocol\JoinGroup\JoinGroupResponseMember;
use Swoole\Kafka\Protocol\Metadata\MetadataResponseTopic;
use Swoole\Kafka\Protocol\SyncGroup\SyncGroupRequestAssignment;

interface PartitionAssignorInterface
{
    /**
     * @param string[] $topics
     */
    public function subscriptionUserData(array $topics): string;

    /**
     * @param MetadataResponseTopic[] $topicMetadatas
     * @param JoinGroupResponseMember[] $groupMembers
     *
     * @return SyncGroupRequestAssignment[]
     */
    public function assign(array $topicMetadatas, array $groupMembers): array;
}
