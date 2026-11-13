<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\DescribeConfigs;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class DescribeConfigsSynonym extends AbstractStruct
{
    /**
     * The synonym name.
     *
     * @var string
     */
    protected $name = '';

    /**
     * The synonym value.
     *
     * @var string|null
     */
    protected $value;

    /**
     * The synonym source.
     *
     * @var int
     */
    protected $source = 0;

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('name', 'string', false, [1, 2, 3], [], [], [], null),
                new ProtocolField('value', 'string', false, [1, 2, 3], [], [0, 1, 2, 3], [], null),
                new ProtocolField('source', 'int8', false, [1, 2, 3], [], [], [], null),
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

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getSource(): int
    {
        return $this->source;
    }

    public function setSource(int $source): self
    {
        $this->source = $source;

        return $this;
    }
}
