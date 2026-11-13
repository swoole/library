<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Util;

class ObjectKeyArray implements \Iterator, \ArrayAccess
{
    /**
     * @var array
     */
    protected $keys = [];

    /**
     * @var array
     */
    protected $values = [];

    /**
     * @param mixed $value
     */
    private function __hash($value): ?string
    {
        if (!\is_object($value)) {
            return null;
        }
        if (method_exists($value, '__toString')) {
            return (string) $value;
        }
        return spl_object_hash($value);
    }

    public function getKeys(): array
    {
        return $this->keys;
    }

    public function getValues(): array
    {
        return $this->values;
    }

    public function offsetExists(mixed $offset): bool
    {
        $hash = $this->__hash($offset);
        if ($hash === null) {
            return false;
        }

        return isset($this->values[$hash]);
    }

    public function &offsetGet(mixed $offset): mixed
    {
        $hash = $this->__hash($offset);
        if ($hash === null) {
            $value = null;

            return $value;
        }

        if (!isset($this->values[$hash])) {
            $value = null;

            return $value;
        }

        return $this->values[$hash];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $hash = $this->__hash($offset);
        if ($hash === null) {
            return;
        }
        $this->values[$hash] = $value;
        $this->keys[$hash]   = $offset;
    }

    public function offsetUnset(mixed $offset): void
    {
        $hash = $this->__hash($offset);
        if ($hash === null) {
            return;
        }

        if (!isset($this->values[$hash])) {
            return;
        }

        unset($this->values[$hash], $this->keys[$hash]);
    }

    public function &current(): mixed
    {
        return $this->values[key($this->values)];
    }

    public function key(): mixed
    {
        $hash = key($this->values);

        if ($hash === null) {
            return null;
        }

        return $this->keys[$hash];
    }

    public function next(): void
    {
        next($this->values);
    }

    public function rewind(): void
    {
        reset($this->values);
    }

    public function valid(): bool
    {
        $hash = key($this->values);

        if ($hash === null) {
            return false;
        }

        return isset($this->keys[$hash]);
    }

    public function size(): int
    {
        return \count($this->values);
    }
}
