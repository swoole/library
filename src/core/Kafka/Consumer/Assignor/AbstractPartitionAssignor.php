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

use Swoole\Kafka\Protocol\Metadata\MetadataResponseTopic;

abstract class AbstractPartitionAssignor implements PartitionAssignorInterface
{
    /**
     * @param string[] $topics
     */
    public function subscriptionUserData(array $topics): string
    {
        return '';
    }

    /**
     * @param MetadataResponseTopic[] $topicMetadatas
     */
    protected function getTopicPartitions(string $topic, array $topicMetadatas): array
    {
        foreach ($topicMetadatas as $metadata) {
            if ($metadata->getName() === $topic) {
                $partitions = [];
                foreach ($metadata->getPartitions() as $partition) {
                    $partitions[] = $partition->getPartitionIndex();
                }

                return $partitions;
            }
        }

        return [];
    }
}
