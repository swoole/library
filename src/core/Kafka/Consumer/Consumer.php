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

use Swoole\Kafka\Broker;
use Swoole\Kafka\Consumer\Assignor\PartitionAssignorInterface;
use Swoole\Kafka\Consumer\Struct\ConsumerGroupMemberMetadata;
use Swoole\Kafka\Exception\KafkaErrorException;
use Swoole\Kafka\Group\CoordinatorType;
use Swoole\Kafka\Group\GroupManager;
use Swoole\Kafka\Group\ProtocolType;
use Swoole\Kafka\Group\Struct\ConsumerGroupMemberAssignment;
use Swoole\Kafka\Group\Struct\ConsumerGroupTopic;
use Swoole\Kafka\Protocol\ErrorCode;
use Swoole\Kafka\Protocol\Fetch\FetchableTopic;
use Swoole\Kafka\Protocol\Fetch\FetchPartition;
use Swoole\Kafka\Protocol\Fetch\FetchRequest;
use Swoole\Kafka\Protocol\Fetch\FetchResponse;
use Swoole\Kafka\Protocol\FindCoordinator\FindCoordinatorResponse;
use Swoole\Kafka\Protocol\JoinGroup\JoinGroupRequestProtocol;
use Swoole\Kafka\Timer\TimerInterface;
use Swoole\Kafka\Trace;
use Swoole\Kafka\Util\KafkaUtil;

class Consumer
{
    /**
     * @var ConsumerConfig
     */
    protected $config;

    /**
     * @var Broker
     */
    protected $broker;

    /**
     * @var callable|null
     */
    protected $consumeCallback;

    /**
     * @var GroupManager
     */
    protected $groupManager;

    /**
     * @var OffsetManager[]
     */
    protected $offsetManagers = [];

    /**
     * @var string
     */
    protected $memberId = '';

    /**
     * @var int
     */
    protected $generationId;

    /**
     * @var array
     */
    protected $fetchOptions = [];

    /**
     * @var int
     */
    protected $emptyMessageCountInLoop = 0;

    /**
     * @var TimerInterface
     */
    protected $timer;

    /**
     * @var bool
     */
    private $manualAssignment = false;

    /**
     * @var int
     */
    private $rejoinAttempts = 0;

    /**
     * @var bool
     */
    private $started = false;

    /**
     * @var ConsumeMessage[]
     */
    private $messages = [];

    /**
     * @var float
     */
    private $lastHeartbeatTime = 0;

    /**
     * @var int|null
     */
    private $heartbeatTimerId;

    /**
     * @var ConsumerGroupMemberAssignment
     */
    private $consumerGroupMemberAssignment;

    /**
     * @var FindCoordinatorResponse
     */
    private $coordinator;

    public function __construct(ConsumerConfig $config, ?callable $consumeCallback = null)
    {
        $this->config          = $config;
        $this->consumeCallback = $consumeCallback;

        $timerClass  = KafkaUtil::getTimerClass($config->getTimer());
        $this->timer = new $timerClass();

        // Topic names are filled in by resolveTopicPattern() when only a pattern was configured.
        Trace::log('consumer construct');
        $this->broker = $broker = new Broker($config);
        if ($config->getUpdateBrokers()) {
            Trace::log('consumer update brokers');
            $broker->updateBrokers();
        } else {
            $broker->setBrokers($config->getBroker());
        }

        if ($config->getTopicPattern() !== '') {
            Trace::log('consumer topic pattern ' . $config->getTopicPattern());
            $this->resolveTopicPattern();
            Trace::log('consumer topics ' . implode(',', $config->getTopic()));
        }

        if ($config->getAssignedPartitions() !== []) {
            $this->manualAssignment = true;
            Trace::log('consumer assign configured');
            $this->assignConfiguredPartitions();
            Trace::log('consumer construct done assigned');

            return;
        }

        $this->groupManager = $groupManager = new GroupManager($broker);
        $groupId            = $config->getGroupId();

        Trace::log('consumer metadata ' . implode(',', $config->getTopic()));
        $this->broker->updateMetadata($config->getTopic());

        // findCoordinator
        Trace::log('consumer coordinator ' . $groupId);
        $this->coordinator = $groupManager->findCoordinator($groupId, CoordinatorType::GROUP, $config->getGroupRetry(), $config->getGroupRetrySleep());

        Trace::log('consumer rejoin ' . $groupId);
        $this->rejoin();
        Trace::log('consumer construct done ' . $groupId);
    }

    public function rejoin(): void
    {
        rejoinBegin:
        try {
            Trace::log(sprintf('rejoin begin member=%s', $this->memberId));
            $this->stopHeartbeat();

            $config       = $this->config;
            $groupManager = $this->groupManager;
            $groupId      = $config->getGroupId();
            $topics       = $config->getTopic();

            $metadata = new ConsumerGroupMemberMetadata();
            $metadata->setTopics($config->getTopic());
            $metadataContent = $metadata->pack();
            $protocolName    = 'group';
            $protocols       = [
                (new JoinGroupRequestProtocol())->setName($protocolName)->setMetadata($metadataContent),
            ];

            // joinGroup. A static member must send the member id from the previous join, or the broker fences it.
            $memberId           = $this->memberId !== '' ? $this->memberId : $config->getMemberId();
            $response           = $groupManager->joinGroup($groupId, $memberId, ProtocolType::CONSUMER, $config->getGroupInstanceId(), $protocols, (int) ($config->getSessionTimeout() * 1000), (int) ($config->getRebalanceTimeout() * 1000), $config->getGroupRetry(), $config->getGroupRetrySleep());
            $this->memberId     = $response->getMemberId();
            $this->generationId = $response->getGenerationId();

            // syncGroup
            if ($this->groupManager->isLeader()) {
                $assignorClass = $config->getPartitionAssignmentStrategy();
                /** @var PartitionAssignorInterface $assignor */
                $assignor    = new $assignorClass();
                $assignments = $assignor->assign($this->broker->getTopicsMeta(), $this->groupManager->getJoinGroupResponse()->getMembers());
                $response    = $groupManager->syncGroup($groupId, $config->getGroupInstanceId(), $this->memberId, $this->generationId, $protocolName, ProtocolType::CONSUMER, $assignments, $config->getGroupRetry(), $config->getGroupRetrySleep());
            } else {
                $response = $groupManager->syncGroup($groupId, $config->getGroupInstanceId(), $this->memberId, $this->generationId, $protocolName, ProtocolType::CONSUMER, [], $config->getGroupRetry(), $config->getGroupRetrySleep());
            }

            $this->consumerGroupMemberAssignment = $consumerGroupMemberAssignment = new ConsumerGroupMemberAssignment();
            $data                                = $response->getAssignment();
            if ($data !== '') {
                $consumerGroupMemberAssignment->unpack($data);
            }

            $this->initFetchOptions();

            foreach ($topics as $topic) {
                $this->offsetManagers[$topic] = $offsetManager = new OffsetManager($this->broker, $this->coordinator->getNodeId(), $topic, $this->getPartitions($topic), $groupId, $config->getGroupInstanceId(), $this->memberId, $this->generationId);
                $offsetManager->updateOffsets($config->getOffsetRetry());
            }

            $this->rejoinAttempts = 0;
            $this->startHeartbeat();
            Trace::log(sprintf('rejoin done member=%s generation=%d', $this->memberId, $this->generationId));
        } catch (KafkaErrorException $ke) {
            switch ($ke->getCode()) {
                case ErrorCode::REBALANCE_IN_PROGRESS:
                case ErrorCode::UNKNOWN_MEMBER_ID:
                case ErrorCode::FENCED_INSTANCE_ID:
                    if (++$this->rejoinAttempts > $this->config->getGroupRetry()) {
                        throw $ke;
                    }
                    Trace::log(sprintf('rejoin retry code=%d attempt=%d', $ke->getCode(), $this->rejoinAttempts));
                    goto rejoinBegin;
                default:
                    throw $ke;
            }
        }
    }

    public function close(): void
    {
        Trace::log('consumer close member=' . $this->memberId);
        if (!$this->manualAssignment && $this->memberId !== '') {
            $config  = $this->config;
            $groupId = $config->getGroupId();
            $this->groupManager->leaveGroup($groupId, $this->memberId, $config->getGroupInstanceId(), $config->getGroupRetry(), $config->getGroupRetrySleep());
        }
        $this->broker->close();
        $this->stopHeartbeat();
        Trace::log('consumer close done');
    }

    public function start(): void
    {
        $consumeCallback = $this->consumeCallback;
        if ($consumeCallback === null) {
            throw new \InvalidArgumentException('consumeCallback must not null');
        }
        $interval      = (int) ($this->config->getInterval() * 1000000);
        $this->started = true;
        $autoCommit    = $this->config->getAutoCommit();
        while ($this->started) {
            $message = $this->consume();
            if ($message === null) {
                if ($interval > 0 && $this->emptyMessageCountInLoop === \count($this->fetchOptions)) {
                    // When the empty message count is equal with the count of fetch options,
                    // We must sleep some micro seconds to avoid dead cycle.
                    usleep($interval);
                }
            } else {
                $consumeCallback($message);
                if ($autoCommit) {
                    $this->ack($message);
                }
            }
        }
    }

    public function stop(): void
    {
        $this->started = false;
    }

    public function consume(): ?ConsumeMessage
    {
        if ($this->messages === []) {
            $this->fetchMessages();
        }
        return array_shift($this->messages);
    }

    public function ack(ConsumeMessage $message): void
    {
        $offsetManager = $this->getOffsetManager($message->getTopic());
        $partition     = $message->getPartition();
        $offsetManager->addFetchOffset($partition);
        $offsetManager->saveOffsets($partition, $this->config->getOffsetRetry());
    }

    public function getConfig(): ConsumerConfig
    {
        return $this->config;
    }

    public function getBroker(): Broker
    {
        return $this->broker;
    }

    public function getOffsetManager(string $topic): OffsetManager
    {
        if (!isset($this->offsetManagers[$topic])) {
            throw new \RuntimeException(sprintf('Topic %s does not exists', $topic));
        }

        return $this->offsetManagers[$topic];
    }

    protected function initFetchOptions(): void
    {
        $fetchOptions = [];
        $config       = $this->config;
        $broker       = $this->broker;
        $topicsMeta   = $broker->getTopicsMeta();
        foreach ($config->getTopic() as $topic) {
            $currentTopicMetaItem = null;
            foreach ($topicsMeta as $topicMetaItem) {
                if ($topicMetaItem->getName() === $topic) {
                    $currentTopicMetaItem = $topicMetaItem;
                    break;
                }
            }
            if (!$currentTopicMetaItem) {
                continue;
            }
            foreach ($this->getFetchPartitions($topic) as $partition) {
                foreach ($currentTopicMetaItem->getPartitions() as $topicsMetaItemPartition) {
                    if ($partition === $topicsMetaItemPartition->getPartitionIndex()) {
                        $fetchOptions[$topicsMetaItemPartition->getLeaderId()][$topic][] = $partition;
                        break;
                    }
                }
            }
        }
        $this->fetchOptions = $fetchOptions;
    }

    protected function fetchMessages(): void
    {
        // A fetch during rejoin uses a member the coordinator has not accepted yet.
        // A manually assigned consumer has no group generation and does not heartbeat.
        if (!$this->manualAssignment) {
            if ($this->heartbeatTimerId === null) {
                Trace::log('fetch skipped heartbeat stopped');
                return;
            }
            $this->checkBeartbeat();
        }
        Trace::log('fetch begin');

        $config  = $this->config;
        $request = new FetchRequest();
        $request->setReplicaId($config->getReplicaId());
        $request->setMinBytes($config->getMinBytes());
        $request->setMaxBytes($config->getMaxBytes());
        $request->setMaxWait($config->getMaxWait());
        $request->setRackId($config->getRackId());
        $topics      = [];
        $currentList = current($this->fetchOptions);
        if ($currentList === false) {
            $currentList                   = reset($this->fetchOptions);
            $this->emptyMessageCountInLoop = 0;
        }
        $nodeId = key($this->fetchOptions);
        next($this->fetchOptions);
        if (empty($this->fetchOptions)) {
            // avoid dead cycle.
            Trace::log('fetch sleep empty options');
            sleep(1);
        }
        if (!$currentList) {
            return;
        }
        foreach ($currentList as $topic => $partitions) {
            $fetchPartitions = [];
            foreach ($partitions as $partition) {
                $fetchPartitions[] = (new FetchPartition())->setPartitionIndex($partition)->setFetchOffset($this->getOffsetManager($topic)->getFetchOffset($partition))->setMaxBytes($config->getMaxBytes());
            }
            $topics[] = (new FetchableTopic())->setName($topic)->setFetchPartitions($fetchPartitions);
        }
        $request->setTopics($topics);

        /** @var FetchResponse $response */
        $response  = $this->broker->getClient($nodeId)->sendRecv($request);
        $errorCode = $response->getErrorCode();
        switch ($errorCode) {
            case ErrorCode::REBALANCE_IN_PROGRESS:
                $this->rejoin();

                return;
            default:
                ErrorCode::check($errorCode);
        }

        $messages = [];
        foreach ($response->getTopics() as $topic) {
            $needUpdatePartitions = [];
            foreach ($topic->getPartitions() as $partition) {
                $errorCode = $partition->getErrorCode();
                switch ($errorCode) {
                    case ErrorCode::OFFSET_OUT_OF_RANGE:
                        $needUpdatePartitions[] = $partition->getPartitionIndex();
                        break;
                    case ErrorCode::UNKNOWN_TOPIC_OR_PARTITION:
                    case ErrorCode::LEADER_NOT_AVAILABLE:
                    case ErrorCode::NOT_LEADER_OR_FOLLOWER:
                    case ErrorCode::REPLICA_NOT_AVAILABLE:
                    case ErrorCode::UNKNOWN_MEMBER_ID:
                    case ErrorCode::FENCED_INSTANCE_ID:
                        if ($this->manualAssignment) {
                            ErrorCode::check($errorCode);
                        }
                        $this->rejoin();

                        return;
                    default:
                        ErrorCode::check($errorCode);
                        foreach ($partition->getRecords()->getRecords() as $record) {
                            $messages[] = new ConsumeMessage($this, $topic->getName(), $partition->getPartitionIndex(), $record->getKey(), $record->getValue(), $record->getHeaders());
                        }
                }
            }
            if ($needUpdatePartitions) {
                $offsetManager = $this->getOffsetManager($topic->getName());
                $offsetManager->updateListOffsets($needUpdatePartitions);
            }
        }
        $this->messages = $messages;
        if (empty($messages)) {
            $this->emptyMessageCountInLoop++;
        }
    }

    protected function startHeartbeat(): void
    {
        $this->lastHeartbeatTime = microtime(true);
        $this->heartbeatTimerId  = $this->timer->tick((int) ($this->config->getGroupHeartbeat() * 1000), function () {
            try {
                $this->heartbeat();
            } catch (KafkaErrorException $e) {
                $callback = $this->getConfig()->getExceptionCallback();
                if ($callback) {
                    $callback($e);
                }
            }
        });
    }

    protected function stopHeartbeat(): void
    {
        if ($this->heartbeatTimerId) {
            $this->timer->clear($this->heartbeatTimerId);
            $this->heartbeatTimerId = null;
        }
    }

    protected function heartbeat(): void
    {
        $config = $this->config;
        try {
            $this->groupManager->heartbeat($config->getGroupId(), $config->getGroupInstanceId(), $this->memberId, $this->generationId);
        } catch (KafkaErrorException $kafkaErrorException) {
            switch ($kafkaErrorException->getCode()) {
                case ErrorCode::REBALANCE_IN_PROGRESS:
                case ErrorCode::UNKNOWN_MEMBER_ID:
                case ErrorCode::FENCED_INSTANCE_ID:
                    $this->rejoin();
                    break;
                default:
                    throw $kafkaErrorException;
            }
        }
    }

    protected function checkBeartbeat(): void
    {
        $time = microtime(true);
        if ($time - $this->lastHeartbeatTime >= $this->config->getGroupHeartbeat()) {
            $this->lastHeartbeatTime = $time;
            $this->heartbeat();
        }
    }

    protected function getPartitions(string $topic): array
    {
        $partitions = [];
        foreach ($this->broker->getTopicsMeta() as $topicMeta) {
            if ($topicMeta->getName() === $topic) {
                foreach ($topicMeta->getPartitions() as $partition) {
                    $partitions[] = $partition->getPartitionIndex();
                }
                break;
            }
        }

        return $partitions;
    }

    /**
     * @return int[]
     */
    protected function getFetchPartitions(string $topic): array
    {
        $partitions = [];
        foreach ($this->consumerGroupMemberAssignment->getTopics() as $consumerGroupMemberAssignmentTopic) {
            if ($consumerGroupMemberAssignmentTopic->getTopicName() === $topic) {
                foreach ($consumerGroupMemberAssignmentTopic->getPartitions() as $partition) {
                    $partitions[] = $partition;
                }
                break;
            }
        }

        return $partitions;
    }

    private function resolveTopicPattern(): void
    {
        $pattern = $this->config->getTopicPattern();
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $valid = preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
        if (!$valid) {
            throw new \InvalidArgumentException(sprintf('Invalid topic pattern "%s"', $pattern));
        }

        $this->broker->updateMetadata();
        $topics = [];
        foreach ($this->broker->getTopicsMeta() as $topicMeta) {
            if (preg_match($pattern, $topicMeta->getName()) === 1) {
                $topics[] = $topicMeta->getName();
            }
        }
        if ($topics === []) {
            throw new \InvalidArgumentException(sprintf('No topic matches "%s"', $pattern));
        }
        $this->config->setTopic($topics);
    }

    private function assignConfiguredPartitions(): void
    {
        $config   = $this->config;
        $topics   = [];
        $byTopic  = [];
        foreach ($config->getAssignedPartitions() as $topic => $partitions) {
            $topic      = (string) $topic;
            $topics[]   = $topic;
            $indexes    = [];
            foreach ($partitions as $partition) {
                $indexes[] = (int) $partition;
            }
            $byTopic[$topic] = $indexes;
        }
        $config->setTopic($topics);
        $this->broker->updateMetadata($topics);

        $groupTopics = [];
        foreach ($byTopic as $topic => $indexes) {
            $groupTopics[] = (new ConsumerGroupTopic())->setTopicName($topic)->setPartitions($indexes);
        }
        $assignment = new ConsumerGroupMemberAssignment();
        $assignment->setTopics($groupTopics);
        $this->consumerGroupMemberAssignment = $assignment;
        $this->initFetchOptions();

        $groupId = $config->getGroupId();
        $nodeId  = -1;
        if ($groupId !== '') {
            $this->groupManager = new GroupManager($this->broker);
            $this->coordinator  = $this->groupManager->findCoordinator($groupId, CoordinatorType::GROUP, $config->getGroupRetry(), $config->getGroupRetrySleep());
            $nodeId             = $this->coordinator->getNodeId();
        }
        foreach ($byTopic as $topic => $indexes) {
            $this->offsetManagers[$topic] = $offsetManager = new OffsetManager($this->broker, $nodeId, $topic, $indexes, $groupId, null, '', -1);
            if ($groupId !== '') {
                $offsetManager->updateOffsets($config->getOffsetRetry());
            } else {
                $offsetManager->updateListOffsets($indexes);
            }
        }
    }
}
