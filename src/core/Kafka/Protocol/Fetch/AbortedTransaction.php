<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\Fetch;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class AbortedTransaction extends AbstractStruct
{
    /**
     * The producer id associated with the aborted transaction.
     *
     * @var int
     */
    protected $producerId = 0;

    /**
     * The first offset in the aborted transaction.
     *
     * @var int
     */
    protected $firstOffset = 0;

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('producerId', 'int64', false, [4, 5, 6, 7, 8, 9, 10, 11], [], [], [], null),
                new ProtocolField('firstOffset', 'int64', false, [4, 5, 6, 7, 8, 9, 10, 11], [], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [];
    }

    public function getProducerId(): int
    {
        return $this->producerId;
    }

    public function setProducerId(int $producerId): self
    {
        $this->producerId = $producerId;

        return $this;
    }

    public function getFirstOffset(): int
    {
        return $this->firstOffset;
    }

    public function setFirstOffset(int $firstOffset): self
    {
        $this->firstOffset = $firstOffset;

        return $this;
    }
}
