<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Consumer;

use Swoole\Kafka\Config\CommonConfig;

class ConsumerConfig extends CommonConfig
{
    /**
     * Client class.
     *
     * @var string|null
     */
    protected $client;

    /**
     * Timer class.
     *
     * @var string|null
     */
    protected $timer;

    /**
     * Socket class.
     *
     * @var string|null
     */
    protected $socket;

    /**
     * @var string|string[]|null
     */
    protected $brokers;

    /**
     * @var float|null
     */
    protected $interval = 0;

    /**
     * @var string
     */
    protected $groupId = '';

    /**
     * @var string
     */
    protected $memberId = '';

    /**
     * Null is a dynamic member. A string is a static member id, sent again on every join.
     *
     * @var string|null
     */
    protected $groupInstanceId;

    /**
     * @var float
     */
    protected $sessionTimeout = 60;

    /**
     * @var float
     */
    protected $rebalanceTimeout = 60;

    /**
     * @var string[]
     */
    protected $topic;

    /**
     * @var int
     */
    protected $replicaId = -1;

    /**
     * @var string
     */
    protected $rackId = '';

    /**
     * @var bool
     */
    protected $autoCommit = true;

    /**
     * @var int
     */
    protected $groupRetry = 5;

    /**
     * @var float
     */
    protected $groupRetrySleep = 1;

    /**
     * @var int
     */
    protected $offsetRetry = 5;

    /**
     * @var float
     */
    protected $groupHeartbeat = 3;

    /**
     * @var bool
     */
    protected $autoCreateTopic = true;

    /**
     * @var string
     */
    protected $partitionAssignmentStrategy = Assignor\RangeAssignor::class;

    /**
     * @var int
     */
    protected $minBytes = 1;

    /**
     * @var int
     */
    protected $maxBytes = 128 * 1024 * 1024;

    /**
     * @var int
     */
    protected $maxWait = 1;

    /**
     * Where to start when the group has no committed offset, or the committed offset is outside the log.
     * earliest reads from the start of the retained log. latest reads only messages produced from now on.
     *
     * @var string
     */
    protected $autoOffsetReset = 'earliest';

    /**
     * PCRE pattern, including delimiters. When set, the consumer subscribes to the topic names that match.
     *
     * @var string
     */
    protected $topicPattern = '';

    /**
     * topic => partition indexes. When set, the consumer reads these partitions and does not join a group.
     *
     * @var array<string, int[]>
     */
    protected $assignedPartitions = [];

    public function getClient(): ?string
    {
        return $this->client;
    }

    public function setClient(?string $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getTimer(): ?string
    {
        return $this->timer;
    }

    public function setTimer(?string $timer): self
    {
        $this->timer = $timer;

        return $this;
    }

    public function getSocket(): ?string
    {
        return $this->socket;
    }

    public function setSocket(?string $socket): self
    {
        $this->socket = $socket;

        return $this;
    }

    public function getInterval(): ?float
    {
        return $this->interval;
    }

    public function setInterval(float $interval): self
    {
        $this->interval = $interval;

        return $this;
    }

    public function getGroupId(): string
    {
        return $this->groupId;
    }

    public function setGroupId(string $groupId): self
    {
        $this->groupId = $groupId;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getTopic(): array
    {
        return $this->topic;
    }

    /**
     * @param string|string[] $topic
     */
    public function setTopic($topic): self
    {
        $this->topic = (array) $topic;

        return $this;
    }

    /**
     * @return string|string[]
     */
    public function getBrokers()
    {
        return $this->brokers;
    }

    /**
     * @param string|string[] $brokers
     *
     * @return $this
     */
    public function setBrokers($brokers): self
    {
        $this->brokers = $brokers;

        return $this;
    }

    /**
     * @return string|string[]|null
     */
    public function getBroker()
    {
        return $this->brokers;
    }

    /**
     * @param string|string[]|null $brokers
     *
     * @return $this
     */
    public function setBroker($brokers): self
    {
        $this->brokers = $brokers;

        return $this;
    }

    public function getMemberId(): string
    {
        return $this->memberId;
    }

    public function setMemberId(string $memberId): self
    {
        $this->memberId = $memberId;

        return $this;
    }

    public function getGroupInstanceId(): ?string
    {
        return $this->groupInstanceId;
    }

    public function setGroupInstanceId(?string $groupInstanceId): self
    {
        $this->groupInstanceId = $groupInstanceId;

        return $this;
    }

    public function getSessionTimeout(): float
    {
        return $this->sessionTimeout;
    }

    public function setSessionTimeout(float $sessionTimeout): self
    {
        $this->sessionTimeout = $sessionTimeout;

        return $this;
    }

    public function getRebalanceTimeout(): float
    {
        return $this->rebalanceTimeout;
    }

    public function setRebalanceTimeout(float $rebalanceTimeout): self
    {
        $this->rebalanceTimeout = $rebalanceTimeout;

        return $this;
    }

    public function getReplicaId(): int
    {
        return $this->replicaId;
    }

    public function setReplicaId(int $replicaId): self
    {
        $this->replicaId = $replicaId;

        return $this;
    }

    public function getRackId(): string
    {
        return $this->rackId;
    }

    public function setRackId(string $rackId): self
    {
        $this->rackId = $rackId;

        return $this;
    }

    public function getAutoCommit(): bool
    {
        return $this->autoCommit;
    }

    public function setAutoCommit(bool $autoCommit): self
    {
        $this->autoCommit = $autoCommit;

        return $this;
    }

    public function getGroupRetry(): int
    {
        return $this->groupRetry;
    }

    public function setGroupRetry(int $groupRetry): self
    {
        $this->groupRetry = $groupRetry;

        return $this;
    }

    public function getGroupRetrySleep(): float
    {
        return $this->groupRetrySleep;
    }

    public function setGroupRetrySleep(float $groupRetrySleep): self
    {
        $this->groupRetrySleep = $groupRetrySleep;

        return $this;
    }

    public function getOffsetRetry(): int
    {
        return $this->offsetRetry;
    }

    public function setOffsetRetry(int $offsetRetry): self
    {
        $this->offsetRetry = $offsetRetry;

        return $this;
    }

    public function getGroupHeartbeat(): float
    {
        return $this->groupHeartbeat;
    }

    public function setGroupHeartbeat(float $groupHeartbeat): self
    {
        $this->groupHeartbeat = $groupHeartbeat;

        return $this;
    }

    public function getAutoCreateTopic(): bool
    {
        return $this->autoCreateTopic;
    }

    public function setAutoCreateTopic(bool $autoCreateTopic): self
    {
        $this->autoCreateTopic = $autoCreateTopic;

        return $this;
    }

    public function getPartitionAssignmentStrategy(): string
    {
        return $this->partitionAssignmentStrategy;
    }

    public function setPartitionAssignmentStrategy(string $partitionAssignmentStrategy): self
    {
        $this->partitionAssignmentStrategy = $partitionAssignmentStrategy;

        return $this;
    }

    public function getMinBytes(): int
    {
        return $this->minBytes;
    }

    public function setMinBytes(int $minBytes): self
    {
        $this->minBytes = $minBytes;

        return $this;
    }

    public function getMaxBytes(): int
    {
        return $this->maxBytes;
    }

    public function setMaxBytes(int $maxBytes): self
    {
        $this->maxBytes = $maxBytes;

        return $this;
    }

    public function getMaxWait(): int
    {
        return $this->maxWait;
    }

    public function setMaxWait(int $maxWait): self
    {
        $this->maxWait = $maxWait;

        return $this;
    }

    public function getAutoOffsetReset(): string
    {
        return $this->autoOffsetReset;
    }

    public function setAutoOffsetReset(string $autoOffsetReset): self
    {
        if ($autoOffsetReset !== 'earliest' && $autoOffsetReset !== 'latest') {
            throw new \InvalidArgumentException('autoOffsetReset must be earliest or latest');
        }
        $this->autoOffsetReset = $autoOffsetReset;

        return $this;
    }

    public function getTopicPattern(): string
    {
        return $this->topicPattern;
    }

    public function setTopicPattern(string $topicPattern): self
    {
        $this->topicPattern = $topicPattern;

        return $this;
    }

    /**
     * @return array<string, int[]>
     */
    public function getAssignedPartitions(): array
    {
        return $this->assignedPartitions;
    }

    /**
     * @param array<string, int[]> $assignedPartitions
     */
    public function setAssignedPartitions(array $assignedPartitions): self
    {
        $this->assignedPartitions = $assignedPartitions;

        return $this;
    }
}
