<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\DeleteRecords;

use Swoole\Kafka\Protocol\AbstractRequest;
use Swoole\Kafka\Protocol\ProtocolField;

class DeleteRecordsRequest extends AbstractRequest
{
    /**
     * Each topic that we want to delete records from.
     *
     * @var DeleteRecordsTopic[]
     */
    protected $topics = [];

    /**
     * How long to wait for the deletion to complete, in milliseconds.
     *
     * @var int
     */
    protected $timeoutMs = 0;

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('topics', DeleteRecordsTopic::class, true, [0, 1, 2], [2], [], [], null),
                new ProtocolField('timeoutMs', 'int32', false, [0, 1, 2], [2], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getRequestApiKey(): ?int
    {
        return 21;
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
     * @return DeleteRecordsTopic[]
     */
    public function getTopics(): array
    {
        return $this->topics;
    }

    /**
     * @param DeleteRecordsTopic[] $topics
     */
    public function setTopics(array $topics): self
    {
        $this->topics = $topics;

        return $this;
    }

    public function getTimeoutMs(): int
    {
        return $this->timeoutMs;
    }

    public function setTimeoutMs(int $timeoutMs): self
    {
        $this->timeoutMs = $timeoutMs;

        return $this;
    }
}
