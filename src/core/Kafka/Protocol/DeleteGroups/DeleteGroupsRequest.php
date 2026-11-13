<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\DeleteGroups;

use Swoole\Kafka\Protocol\AbstractRequest;
use Swoole\Kafka\Protocol\ProtocolField;

class DeleteGroupsRequest extends AbstractRequest
{
    /**
     * The group names to delete.
     *
     * @var string[]
     */
    protected $groupsNames = [];

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('groupsNames', 'string', true, [0, 1, 2], [2], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getRequestApiKey(): ?int
    {
        return 42;
    }

    public function getMaxSupportedVersion(): int
    {
        return 2;
    }

    public function getFlexibleVersions(): array
    {
        return [2];
    }

    /**
     * @return string[]
     */
    public function getGroupsNames(): array
    {
        return $this->groupsNames;
    }

    /**
     * @param string[] $groupsNames
     */
    public function setGroupsNames(array $groupsNames): self
    {
        $this->groupsNames = $groupsNames;

        return $this;
    }
}
