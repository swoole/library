<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\AlterClientQuotas;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class OpData extends AbstractStruct
{
    /**
     * The quota configuration key.
     *
     * @var string
     */
    protected $key = '';

    /**
     * The value to set, otherwise ignored if the value is to be removed.
     *
     * @var float
     */
    protected $value = 0;

    /**
     * Whether the quota configuration value should be removed, otherwise set.
     *
     * @var bool
     */
    protected $remove = false;

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('key', 'string', false, [0], [], [], [], null),
                new ProtocolField('value', 'float64', false, [0], [], [], [], null),
                new ProtocolField('remove', 'bool', false, [0], [], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [];
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function setValue(float $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getRemove(): bool
    {
        return $this->remove;
    }

    public function setRemove(bool $remove): self
    {
        $this->remove = $remove;

        return $this;
    }
}
