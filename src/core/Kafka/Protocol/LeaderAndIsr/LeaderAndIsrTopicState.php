<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\LeaderAndIsr;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class LeaderAndIsrTopicState extends AbstractStruct
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
     * @var LeaderAndIsrPartitionState[]
     */
    protected $partitionStates = [];

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('topicName', 'string', false, [2, 3, 4], [4], [], [], null),
                new ProtocolField('partitionStates', LeaderAndIsrPartitionState::class, true, [2, 3, 4], [4], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [4];
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
     * @return LeaderAndIsrPartitionState[]
     */
    public function getPartitionStates(): array
    {
        return $this->partitionStates;
    }

    /**
     * @param LeaderAndIsrPartitionState[] $partitionStates
     */
    public function setPartitionStates(array $partitionStates): self
    {
        $this->partitionStates = $partitionStates;

        return $this;
    }
}
