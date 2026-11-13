<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\DescribeClientQuotas;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class EntryData extends AbstractStruct
{
    /**
     * The quota entity description.
     *
     * @var EntityData[]
     */
    protected $entity = [];

    /**
     * The quota values for the entity.
     *
     * @var ValueData[]
     */
    protected $values = [];

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('entity', EntityData::class, true, [0], [], [], [], null),
                new ProtocolField('values', ValueData::class, true, [0], [], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [];
    }

    /**
     * @return EntityData[]
     */
    public function getEntity(): array
    {
        return $this->entity;
    }

    /**
     * @param EntityData[] $entity
     */
    public function setEntity(array $entity): self
    {
        $this->entity = $entity;

        return $this;
    }

    /**
     * @return ValueData[]
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * @param ValueData[] $values
     */
    public function setValues(array $values): self
    {
        $this->values = $values;

        return $this;
    }
}
