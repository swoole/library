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

class ForgottenTopic extends AbstractStruct
{
    /**
     * The partition name.
     *
     * @var string
     */
    protected $name = '';

    /**
     * The partitions indexes to forget.
     *
     * @var int[]
     */
    protected $forgottenPartitionIndexes = [];

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('name', 'string', false, [7, 8, 9, 10, 11], [], [], [], null),
                new ProtocolField('forgottenPartitionIndexes', 'int32', true, [7, 8, 9, 10, 11], [], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return int[]
     */
    public function getForgottenPartitionIndexes(): array
    {
        return $this->forgottenPartitionIndexes;
    }

    /**
     * @param int[] $forgottenPartitionIndexes
     */
    public function setForgottenPartitionIndexes(array $forgottenPartitionIndexes): self
    {
        $this->forgottenPartitionIndexes = $forgottenPartitionIndexes;

        return $this;
    }
}
