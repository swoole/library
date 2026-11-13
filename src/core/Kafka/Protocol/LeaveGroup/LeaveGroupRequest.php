<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\LeaveGroup;

use Swoole\Kafka\Protocol\AbstractRequest;
use Swoole\Kafka\Protocol\ProtocolField;

class LeaveGroupRequest extends AbstractRequest
{
    /**
     * The ID of the group to leave.
     *
     * @var string
     */
    protected $groupId = '';

    /**
     * The member ID to remove from the group.
     *
     * @var string
     */
    protected $memberId = '';

    /**
     * List of leaving member identities.
     *
     * @var MemberIdentity[]
     */
    protected $members = [];

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('groupId', 'string', false, [0, 1, 2, 3, 4], [4], [], [], null),
                new ProtocolField('memberId', 'string', false, [0, 1, 2], [4], [], [], null),
                new ProtocolField('members', MemberIdentity::class, true, [3, 4], [4], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getRequestApiKey(): ?int
    {
        return 13;
    }

    public function getMaxSupportedVersion(): int
    {
        return 4;
    }

    public function getFlexibleVersions(): array
    {
        return [4];
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

    public function getMemberId(): string
    {
        return $this->memberId;
    }

    public function setMemberId(string $memberId): self
    {
        $this->memberId = $memberId;

        return $this;
    }

    /**
     * @return MemberIdentity[]
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    /**
     * @param MemberIdentity[] $members
     */
    public function setMembers(array $members): self
    {
        $this->members = $members;

        return $this;
    }
}
