<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\DescribeGroups;

use Swoole\Kafka\Protocol\AbstractResponse;
use Swoole\Kafka\Protocol\ProtocolField;

class DescribeGroupsResponse extends AbstractResponse
{
    /**
     * The duration in milliseconds for which the request was throttled due to a quota violation, or zero if the request did not violate any quota.
     *
     * @var int
     */
    protected $throttleTimeMs = 0;

    /**
     * Each described group.
     *
     * @var DescribedGroup[]
     */
    protected $groups = [];

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('throttleTimeMs', 'int32', false, [1, 2, 3, 4, 5], [5], [], [], null),
                new ProtocolField('groups', DescribedGroup::class, true, [0, 1, 2, 3, 4, 5], [5], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getRequestApiKey(): ?int
    {
        return 15;
    }

    public function getFlexibleVersions(): array
    {
        return [5];
    }

    public function getThrottleTimeMs(): int
    {
        return $this->throttleTimeMs;
    }

    public function setThrottleTimeMs(int $throttleTimeMs): self
    {
        $this->throttleTimeMs = $throttleTimeMs;

        return $this;
    }

    /**
     * @return DescribedGroup[]
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @param DescribedGroup[] $groups
     */
    public function setGroups(array $groups): self
    {
        $this->groups = $groups;

        return $this;
    }
}
