<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole;

class MultibyteStringObject extends StringObject
{
    public function length(): int
    {
        return mb_strlen($this->string);
    }

    public function indexOf(string $needle, int $offset = 0, ?string $encoding = null): false|int
    {
        return mb_strpos($this->string, $needle, $offset, $encoding);
    }

    public function lastIndexOf(string $needle, int $offset = 0, ?string $encoding = null): false|int
    {
        return mb_strrpos($this->string, $needle, $offset, $encoding);
    }

    public function pos(string $needle, int $offset = 0, ?string $encoding = null): false|int
    {
        return mb_strpos($this->string, $needle, $offset, $encoding);
    }

    public function rpos(string $needle, int $offset = 0, ?string $encoding = null): false|int
    {
        return mb_strrpos($this->string, $needle, $offset, $encoding);
    }

    public function ipos(string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        return mb_stripos($this->string, $needle, $offset, $encoding);
    }

    /**
     * @see https://www.php.net/mb_substr
     */
    public function substr(int $start, ?int $length = null, ?string $encoding = null): static
    {
        return new static(mb_substr($this->string, $start, $length, $encoding)); // @phpstan-ignore new.static
    }

    /**
     * @todo This method is not implemented correctly.
     */
    public function chunk(int $splitLength = 1, ?int $limit = null): ArrayObject
    {
        return static::detectArrayType(mb_split($this->string, ...func_get_args()));
    }
}
