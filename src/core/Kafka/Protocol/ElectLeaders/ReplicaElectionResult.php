<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\ElectLeaders;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class ReplicaElectionResult extends AbstractStruct
{
    /**
     * The topic name.
     *
     * @var string
     */
    protected $topic = '';

    /**
     * The results for each partition.
     *
     * @var PartitionResult[]
     */
    protected $partitionResult = [];

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('topic', 'string', false, [0, 1, 2], [2], [], [], null),
                new ProtocolField('partitionResult', PartitionResult::class, true, [0, 1, 2], [2], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [2];
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function setTopic(string $topic): self
    {
        $this->topic = $topic;

        return $this;
    }

    /**
     * @return PartitionResult[]
     */
    public function getPartitionResult(): array
    {
        return $this->partitionResult;
    }

    /**
     * @param PartitionResult[] $partitionResult
     */
    public function setPartitionResult(array $partitionResult): self
    {
        $this->partitionResult = $partitionResult;

        return $this;
    }
}
