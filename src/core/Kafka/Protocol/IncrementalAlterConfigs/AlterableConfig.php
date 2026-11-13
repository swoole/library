<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\IncrementalAlterConfigs;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class AlterableConfig extends AbstractStruct
{
    /**
     * The configuration key name.
     *
     * @var string
     */
    protected $name = '';

    /**
     * The type (Set, Delete, Append, Subtract) of operation.
     *
     * @var int
     */
    protected $configOperation = 0;

    /**
     * The value to set for the configuration key.
     *
     * @var string|null
     */
    protected $value;

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('name', 'string', false, [0, 1], [1], [], [], null),
                new ProtocolField('configOperation', 'int8', false, [0, 1], [1], [], [], null),
                new ProtocolField('value', 'string', false, [0, 1], [1], [0, 1], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [1];
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

    public function getConfigOperation(): int
    {
        return $this->configOperation;
    }

    public function setConfigOperation(int $configOperation): self
    {
        $this->configOperation = $configOperation;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;

        return $this;
    }
}
