<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Producer;

use Swoole\Kafka\Broker;
use Swoole\Kafka\Producer\Partitioner\PartitionerInterface;
use Swoole\Kafka\Protocol\ErrorCode;
use Swoole\Kafka\Protocol\Produce\PartitionProduceData;
use Swoole\Kafka\Protocol\Produce\ProduceRequest;
use Swoole\Kafka\Protocol\Produce\ProduceResponse;
use Swoole\Kafka\Protocol\Produce\TopicProduceData;
use Swoole\Kafka\Protocol\RecordBatch\Record;
use Swoole\Kafka\Protocol\RecordBatch\RecordBatch;
use Swoole\Kafka\Protocol\RecordBatch\RecordHeader;

class Producer
{
    /**
     * @var ProducerConfig
     */
    protected $config;

    /**
     * @var Broker
     */
    protected $broker;

    /**
     * @var PartitionerInterface
     */
    protected $partitioner;

    public function __construct(ProducerConfig $config)
    {
        $this->config = $config;
        $this->broker = $broker = new Broker($config);
        if ($config->getUpdateBrokers()) {
            $broker->updateBrokers();
        } else {
            $broker->setBrokers($config->getBrokers());
        }
        $class             = $config->getPartitioner();
        $this->partitioner = new $class();
    }

    /**
     * @param RecordHeader[]|array $headers
     */
    public function send(string $topic, ?string $value, ?string $key = null, array $headers = [], ?int $partitionIndex = null): void
    {
        $message  = new ProduceMessage($topic, $value, $key, $headers, $partitionIndex);
        $messages = [$message];
        $this->sendBatch($messages);
    }

    /**
     * @param ProduceMessage[] $messages
     */
    public function sendBatch(array $messages): void
    {
        $config  = $this->config;
        $broker  = $this->broker;
        $request = new ProduceRequest();
        $request->setAcks($acks = $config->getAcks());
        $recvTimeout = $config->getRecvTimeout();
        if ($recvTimeout < 0) {
            $request->setTimeoutMs(60000);
        } else {
            $request->setTimeoutMs((int) ($recvTimeout * 1000));
        }

        $timestamp = (int) (microtime(true) * 1000);
        /** @var TopicProduceData[][] $topicsMap */
        $topicsMap     = [];
        $partitionsMap = [];
        $topics        = [];
        foreach ($messages as $message) {
            $topics[] = $message->getTopic();
        }
        $topicsMeta = $broker->getTopicsMeta($topics);
        foreach ($messages as $message) {
            $topicName      = $message->getTopic();
            $value          = $message->getValue();
            $key            = $message->getKey();
            $partitionIndex = $message->getPartitionIndex() ?? $this->partitioner->partition($topicName, $value, $key, $topicsMeta);
            $brokerId       = $broker->getBrokerIdByTopic($topicName, $partitionIndex);
            if (isset($topicsMap[$brokerId][$topicName])) {
                /** @var TopicProduceData $topicData */
                $topicData  = $topicsMap[$brokerId][$topicName];
                $partitions = $topicData->getPartitions();
            } else {
                $topicData = $topicsMap[$brokerId][$topicName] = new TopicProduceData();
                $topicData->setName($topicName);
                $partitions = [];
            }
            if (isset($partitionsMap[$topicName][$partitionIndex])) {
                /** @var PartitionProduceData $partition */
                $partition   = $partitionsMap[$topicName][$partitionIndex];
                $recordBatch = $partition->getRecords();
                $records     = $recordBatch->getRecords();
            } else {
                $partition = $partitions[] = $partitionsMap[$topicName][$partitionIndex] = new PartitionProduceData();
                $partition->setPartitionIndex($partitionIndex);
                $partition->setRecords($recordBatch = new RecordBatch());
                $recordBatch->setProducerId($config->getProducerId());
                $recordBatch->setProducerEpoch($config->getProducerEpoch());
                $recordBatch->setPartitionLeaderEpoch($config->getPartitionLeaderEpoch());
                $recordBatch->setFirstTimestamp($timestamp);
                $recordBatch->setMaxTimestamp($timestamp);
                $recordBatch->setLastOffsetDelta(-1);
                $records = [];
            }
            $offsetDelta = $recordBatch->getLastOffsetDelta() + 1;
            $recordBatch->setLastOffsetDelta($offsetDelta);
            $record = $records[] = new Record();
            $record->setKey($key);
            $record->setValue($value);
            $headers = [];
            foreach ($message->getHeaders() as $key => $value) {
                if ($value instanceof RecordHeader) {
                    $headers[] = $value;
                } else {
                    $headers[] = (new RecordHeader())->setHeaderKey((string) $key)->setValue($value);
                }
            }
            $record->setHeaders($headers);
            $record->setOffsetDelta($offsetDelta);
            $record->setTimestampDelta(((int) (microtime(true) * 1000)) - $timestamp);
            $recordBatch->setRecords($records);

            $topicData->setPartitions($partitions);
        }
        $produceRetry      = $config->getProduceRetry();
        $produceRetrySleep = $config->getProduceRetrySleep();
        foreach ($topicsMap as $brokerId => $topics) {
            $retryTopics = [];
            for ($retryCount = 0; $retryCount <= $produceRetry; $retryCount++) {
                if ($retryTopics) {
                    foreach ($topics as $k => $v) {
                        $name = $v->getName();
                        if (isset($retryTopics[$name])) {
                            $partitions = $v->getPartitions();
                            foreach ($partitions as $i => $partition) {
                                if (!\in_array($partition->getPartitionIndex(), $retryTopics[$name])) {
                                    unset($partitions[$i]);
                                }
                            }
                            $v->setPartitions($partitions);
                        } else {
                            unset($topics[$k]);
                        }
                    }
                }
                $request->setTopics($topics);

                $hasResponse   = $acks !== 0;
                $client        = $broker->getClient($brokerId);
                $correlationId = $client->send($request, null, $hasResponse);
                if (!$hasResponse) {
                    break;
                }
                /** @var ProduceResponse $response */
                $response    = $client->recv($correlationId);
                $retryTopics = [];
                $lastError   = ErrorCode::NONE;
                foreach ($response->getResponses() as $response) {
                    $topicName = $response->getName();
                    foreach ($response->getPartitions() as $partition) {
                        $errorCode = $partition->getErrorCode();
                        switch ($errorCode) {
                            case ErrorCode::UNKNOWN_TOPIC_OR_PARTITION:
                            case ErrorCode::LEADER_NOT_AVAILABLE:
                            case ErrorCode::NOT_LEADER_OR_FOLLOWER:
                            case ErrorCode::REPLICA_NOT_AVAILABLE:
                                // A topic that was just created, or whose leader is still being elected, answers with one of these.
                                $retryTopics[$topicName][]  = $partition->getPartitionIndex();
                                $lastError                  = $errorCode;
                                break;
                            default:
                                ErrorCode::check($errorCode);
                        }
                    }
                }
                if (!$retryTopics) {
                    break;
                }
                if ($retryCount === $produceRetry) {
                    ErrorCode::check($lastError);
                }
                usleep((int) ($produceRetrySleep * 1000000));
            }
        }
    }

    public function close(): void
    {
        $this->broker->close();
    }

    public function getConfig(): ProducerConfig
    {
        return $this->config;
    }

    public function getBroker(): Broker
    {
        return $this->broker;
    }
}
