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

interface PartitionerInterface
{
    /**
     * @param MetadataResponseTopic[] $topicsMeta
     */
    public function partition(string $topic, ?string $value, ?string $key, array $topicsMeta): int;
}
