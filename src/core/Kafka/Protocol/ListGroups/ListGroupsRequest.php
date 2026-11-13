<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\ListGroups;

use Swoole\Kafka\Protocol\AbstractRequest;
use Swoole\Kafka\Protocol\ProtocolField;

class ListGroupsRequest extends AbstractRequest
{
    /**
     * The states of the groups we want to list. If empty all groups are returned with their state.
     *
     * @var string[]
     */
    protected $statesFilter = [];

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('statesFilter', 'string', true, [4], [3, 4], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getRequestApiKey(): ?int
    {
        return 16;
    }

    public function getMaxSupportedVersion(): int
    {
        return 4;
    }

    public function getFlexibleVersions(): array
    {
        return [3, 4];
    }

    /**
     * @return string[]
     */
    public function getStatesFilter(): array
    {
        return $this->statesFilter;
    }

    /**
     * @param string[] $statesFilter
     */
    public function setStatesFilter(array $statesFilter): self
    {
        $this->statesFilter = $statesFilter;

        return $this;
    }
}
