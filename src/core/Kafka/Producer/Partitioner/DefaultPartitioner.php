<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Producer\Partitioner;

use Swoole\Kafka\Protocol\Metadata\MetadataResponseTopic;

/**
 * The default partitioning strategy:.
 *
 * if partition !== null, then use partition
 * if partition === null && key !== null, then use crc32(key) % partitions to select partition
 * if partition === null && key === null, then use Round Robin to select partition
 */
class DefaultPartitioner implements PartitionerInterface
{
    /**
     * @var array
     */
    private $indexCache = [];

    /**
     * @param MetadataResponseTopic[] $topicsMeta
     */
    public function partition(string $topic, ?string $value, ?string $key, array $topicsMeta): int
    {
        if ($key === null) {
            return $this->roundRobin($topic, $topicsMeta);
        }
        return $this->hash($topic, $key, $topicsMeta);
    }

    /**
     * @param MetadataResponseTopic[] $topicsMeta
     */
    private function roundRobin(string $topic, array $topicsMeta): int
    {
        $partitionCount = $this->getPartitionCount($topicsMeta, $topic);
        if (isset($this->indexCache[$topic])) {
            return (++$this->indexCache[$topic]) % $partitionCount;
        }
        return $this->indexCache[$topic] = mt_rand() % $partitionCount;
    }

    private function hash(string $topic, string $key, array $topicsMeta): int
    {
        $partitionCount = $this->getPartitionCount($topicsMeta, $topic);

        return crc32($key) % $partitionCount;
    }

    private function getPartitionCount(array $topicsMeta, string $topic): int
    {
        foreach ($topicsMeta as $item) {
            if ($topic === $item->getName()) {
                return \count($item->getPartitions());
            }
        }
        throw new \RuntimeException(sprintf('Get topic %s partitions count failed', $topic));
    }
}
