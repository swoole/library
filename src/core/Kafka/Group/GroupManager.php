<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Group;

use Swoole\Kafka\Broker;
use Swoole\Kafka\Client\ClientInterface;
use Swoole\Kafka\Protocol\ErrorCode;
use Swoole\Kafka\Protocol\FindCoordinator\FindCoordinatorRequest;
use Swoole\Kafka\Protocol\FindCoordinator\FindCoordinatorResponse;
use Swoole\Kafka\Protocol\Heartbeat\HeartbeatRequest;
use Swoole\Kafka\Protocol\Heartbeat\HeartbeatResponse;
use Swoole\Kafka\Protocol\JoinGroup\JoinGroupRequest;
use Swoole\Kafka\Protocol\JoinGroup\JoinGroupResponse;
use Swoole\Kafka\Protocol\LeaveGroup\LeaveGroupRequest;
use Swoole\Kafka\Protocol\LeaveGroup\LeaveGroupResponse;
use Swoole\Kafka\Protocol\LeaveGroup\MemberIdentity;
use Swoole\Kafka\Protocol\SyncGroup\SyncGroupRequest;
use Swoole\Kafka\Protocol\SyncGroup\SyncGroupResponse;
use Swoole\Kafka\Util\KafkaUtil;

class GroupManager
{
    /**
     * @var Broker
     */
    protected $broker;

    /**
     * @var bool
     */
    protected $isLeader = false;

    /**
     * @var FindCoordinatorResponse
     */
    protected $findCoordinatorResponse;

    /**
     * @var JoinGroupResponse
     */
    protected $joinGroupResponse;

    public function __construct(Broker $broker)
    {
        $this->broker = $broker;
    }

    public function findCoordinator(string $key, int $keyType = CoordinatorType::GROUP, int $retry = 0, float $sleep = 0.01): FindCoordinatorResponse
    {
        $request = new FindCoordinatorRequest();
        $request->setKey($key);
        $request->setKeyType($keyType);

        // @phpstan-ignore-next-line
        return $this->findCoordinatorResponse = KafkaUtil::retry($this->broker->getClient(), $request, $retry, $sleep);
    }

    public function joinGroup(string $groupId, string $memberId, string $protocolType, ?string $groupInstanceId = null, array $protocols = [], int $sessionTimeoutMs = 60000, int $rebalanceTimeoutMs = -1, int $retry = 0, float $sleep = 0.01): JoinGroupResponse
    {
        $request = new JoinGroupRequest();
        $request->setGroupId($groupId);
        $request->setGroupInstanceId($groupInstanceId);
        $request->setMemberId($memberId);
        $request->setProtocolType($protocolType);
        $request->setProtocols($protocols);
        $request->setSessionTimeoutMs($sessionTimeoutMs);
        $request->setRebalanceTimeoutMs($rebalanceTimeoutMs);

        /** @var JoinGroupResponse $response */
        // @phpstan-ignore-next-line
        $response = $this->joinGroupResponse = KafkaUtil::retry($this->broker->getClient($this->findCoordinatorResponse->getNodeId()), $request, $retry, $sleep, [
            ErrorCode::MEMBER_ID_REQUIRED => function (JoinGroupResponse $response) use ($request, $retry, $sleep) {
                $request->setMemberId($response->getMemberId());

                return KafkaUtil::retry($this->broker->getClient($this->findCoordinatorResponse->getNodeId()), $request, $retry, $sleep);
            },
        ]);

        $this->isLeader = $response->getLeader() === $response->getMemberId();

        return $response;
    }

    public function leaveGroup(string $groupId, string $memberId, ?string $groupInstanceId, int $retry = 0, float $sleep = 0.01): LeaveGroupResponse
    {
        $request = new LeaveGroupRequest();
        $request->setGroupId($groupId);
        $request->setMemberId($memberId);
        $request->setMembers([
            (new MemberIdentity())->setMemberId($memberId)->setGroupInstanceId($groupInstanceId),
        ]);

        // @phpstan-ignore-next-line
        return KafkaUtil::retry($this->broker->getClient($this->findCoordinatorResponse->getNodeId()), $request, $retry, $sleep);
    }

    public function syncGroup(string $groupId, string $groupInstanceId, string $memberId, int $generationId, string $protocolName, string $protocolType, array $assignments, int $retry = 0, float $sleep = 0.01): SyncGroupResponse
    {
        $request = new SyncGroupRequest();
        $request->setGroupId($groupId);
        $request->setGroupInstanceId($groupInstanceId);
        $request->setMemberId($memberId);
        $request->setGenerationId($generationId);
        $request->setProtocolName($protocolName);
        $request->setProtocolType($protocolType);
        $request->setAssignments($assignments);

        // @phpstan-ignore-next-line
        return KafkaUtil::retry($this->broker->getClient($this->findCoordinatorResponse->getNodeId()), $request, $retry, $sleep);
    }

    public function heartbeat(string $groupId, string $groupInstanceId, string $memberId, int $generationId, int $retry = 0, float $sleep = 0.01): HeartbeatResponse
    {
        $request = new HeartbeatRequest();
        $request->setGroupId($groupId);
        $request->setGroupInstanceId($groupInstanceId);
        $request->setGenerationId($generationId);
        $request->setMemberId($memberId);

        // @phpstan-ignore-next-line
        return KafkaUtil::retry($this->broker->getClient($this->findCoordinatorResponse->getNodeId()), $request, $retry, $sleep);
    }

    public function getClient(): ClientInterface
    {
        return $this->broker->getClient();
    }

    public function isLeader(): bool
    {
        return $this->isLeader;
    }

    public function getJoinGroupResponse(): JoinGroupResponse
    {
        return $this->joinGroupResponse;
    }
}
