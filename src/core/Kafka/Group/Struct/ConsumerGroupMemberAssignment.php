<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Group\Struct;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class ConsumerGroupMemberAssignment extends AbstractStruct
{
    /**
     * @var int
     */
    protected $version = 0;

    /**
     * @var ConsumerGroupTopic[]
     */
    protected $topics = [];

    /**
     * @var string
     */
    protected $userData = '';

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('version', 'int16', false, [0], [], [], [], null),
                new ProtocolField('topics', ConsumerGroupTopic::class, true, [0], [], [], [], null),
                new ProtocolField('userData', 'bytes', false, [0], [], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getMaxSupportedVersion(): int
    {
        return 0;
    }

    public function getFlexibleVersions(): array
    {
        return [];
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;

        return $this;
    }

    /**
     * @return ConsumerGroupTopic[]
     */
    public function getTopics(): array
    {
        return $this->topics;
    }

    /**
     * @param ConsumerGroupTopic[] $topics
     */
    public function setTopics(array $topics): self
    {
        $this->topics = $topics;

        return $this;
    }

    public function getUserData(): string
    {
        return $this->userData;
    }

    public function setUserData(string $userData): self
    {
        $this->userData = $userData;

        return $this;
    }
}
