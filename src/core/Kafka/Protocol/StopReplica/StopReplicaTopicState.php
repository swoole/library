<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\StopReplica;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class StopReplicaTopicState extends AbstractStruct
{
    /**
     * The topic name.
     *
     * @var string
     */
    protected $topicName = '';

    /**
     * The state of each partition.
     *
     * @var StopReplicaPartitionState[]
     */
    protected $partitionStates = [];

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('topicName', 'string', false, [3], [2, 3], [], [], null),
                new ProtocolField('partitionStates', StopReplicaPartitionState::class, true, [3], [2, 3], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [2, 3];
    }

    public function getTopicName(): string
    {
        return $this->topicName;
    }

    public function setTopicName(string $topicName): self
    {
        $this->topicName = $topicName;

        return $this;
    }

    /**
     * @return StopReplicaPartitionState[]
     */
    public function getPartitionStates(): array
    {
        return $this->partitionStates;
    }

    /**
     * @param StopReplicaPartitionState[] $partitionStates
     */
    public function setPartitionStates(array $partitionStates): self
    {
        $this->partitionStates = $partitionStates;

        return $this;
    }
}
