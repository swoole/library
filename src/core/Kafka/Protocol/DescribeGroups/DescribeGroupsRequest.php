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

use Swoole\Kafka\Protocol\AbstractRequest;
use Swoole\Kafka\Protocol\ProtocolField;

class DescribeGroupsRequest extends AbstractRequest
{
    /**
     * The names of the groups to describe.
     *
     * @var string[]
     */
    protected $groups = [];

    /**
     * Whether to include authorized operations.
     *
     * @var bool
     */
    protected $includeAuthorizedOperations = false;

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('groups', 'string', true, [0, 1, 2, 3, 4, 5], [5], [], [], null),
                new ProtocolField('includeAuthorizedOperations', 'bool', false, [3, 4, 5], [5], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getRequestApiKey(): ?int
    {
        return 15;
    }

    public function getMaxSupportedVersion(): int
    {
        return 5;
    }

    public function getFlexibleVersions(): array
    {
        return [5];
    }

    /**
     * @return string[]
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @param string[] $groups
     */
    public function setGroups(array $groups): self
    {
        $this->groups = $groups;

        return $this;
    }

    public function getIncludeAuthorizedOperations(): bool
    {
        return $this->includeAuthorizedOperations;
    }

    public function setIncludeAuthorizedOperations(bool $includeAuthorizedOperations): self
    {
        $this->includeAuthorizedOperations = $includeAuthorizedOperations;

        return $this;
    }
}
