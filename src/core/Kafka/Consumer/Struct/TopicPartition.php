<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Consumer\Struct;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class TopicPartition extends AbstractStruct
{
    /**
     * @var string
     */
    protected $topic;

    /**
     * @var int
     */
    protected $partition;

    public function __construct(?string $topic = null, ?int $partition = null)
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('topic', 'string', false, [1], [], [], [], null),
                new ProtocolField('partition', 'int32', false, [1], [], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
        $this->topic     = $topic;
        $this->partition = $partition;
    }

    public function __toString()
    {
        return $this->topic . '-' . $this->partition;
    }

    public function getMaxSupportedVersion(): int
    {
        return 1;
    }

    public function getFlexibleVersions(): array
    {
        return [];
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function setTopic(string $topic): self
    {
        $this->topic = $topic;

        return $this;
    }

    public function getPartition(): int
    {
        return $this->partition;
    }

    public function setPartition(int $partition): self
    {
        $this->partition = $partition;

        return $this;
    }
}
