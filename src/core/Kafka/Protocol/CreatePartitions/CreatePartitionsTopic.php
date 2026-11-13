<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\CreatePartitions;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class CreatePartitionsTopic extends AbstractStruct
{
    /**
     * The topic name.
     *
     * @var string
     */
    protected $name = '';

    /**
     * The new partition count.
     *
     * @var int
     */
    protected $count = 0;

    /**
     * The new partition assignments.
     *
     * @var CreatePartitionsAssignment[]|null
     */
    protected $assignments;

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('name', 'string', false, [0, 1, 2], [2], [], [], null),
                new ProtocolField('count', 'int32', false, [0, 1, 2], [2], [], [], null),
                new ProtocolField('assignments', CreatePartitionsAssignment::class, true, [0, 1, 2], [2], [0, 1, 2], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [2];
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

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): self
    {
        $this->count = $count;

        return $this;
    }

    /**
     * @return CreatePartitionsAssignment[]|null
     */
    public function getAssignments(): ?array
    {
        return $this->assignments;
    }

    /**
     * @param CreatePartitionsAssignment[]|null $assignments
     */
    public function setAssignments(?array $assignments): self
    {
        $this->assignments = $assignments;

        return $this;
    }
}
