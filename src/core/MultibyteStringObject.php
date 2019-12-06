<?php

namespace Swoole;


class MultibyteStringObject extends StringObject
{
    /**
     * @return int
     */
    public function length(): int
    {
        return mb_strlen($this->string);
    }

    /**
     * @param string $needle
     * @param int $offset
     * @return bool|int
     */
    public function indexOf(string $needle, int $offset = 0)
    {
        return mb_strpos($this->string, $needle, $offset);
    }

    /**
     * @param string $needle
     * @param int $offset
     * @return bool|int
     */
    public function lastIndexOf(string $needle, int $offset = 0)
    {
        return mb_strrpos($this->string, $needle, $offset);
    }

    /**
     * @param string $needle
     * @param int $offset
     * @return bool|int
     */
    public function pos(string $needle, int $offset = 0)
    {
        return mb_strpos($this->string, $needle, $offset);
    }

    /**
     * @param string $needle
     * @param int $offset
     * @return bool|int
     */
    public function rpos(string $needle, int $offset = 0)
    {
        return mb_strrpos($this->string, $needle, $offset);
    }

    /**
     * @param string $needle
     * @return bool|int
     */
    public function ipos(string $needle)
    {
        return mb_stripos($this->string, $needle);
    }

    /**
     * @param int $offset
     * @param mixed ...$length
     * @return static
     */
    public function substr(int $offset, ...$length)
    {
        return new static(mb_substr($this->string, $offset, ...$length));
    }

    /**
     * @param string $needle
     * @return bool
     */
    public function startsWith(string $needle): bool
    {
        return mb_strpos($this->string, $needle) === 0;
    }

    /**
     * @param string $subString
     * @return bool
     */
    public function contains(string $subString): bool
    {
        return mb_strpos($this->string, $subString) !== false;
    }

    /**
     * @param string $needle
     * @return bool
     */
    public function endsWith(string $needle): bool
    {
        return mb_strrpos($this->string, $needle) === (strlen($needle) - 1);
    }

    /**
     * @param string $delimiter
     * @param int $limit
     * @return ArrayObject
     */
    public function split(string $delimiter, int $limit = PHP_INT_MAX): ArrayObject
    {
        return static::detectArrayType(explode($delimiter, $this->string, $limit));
    }

    /**
     * @param int $splitLength
     * @return ArrayObject
     */
    public function chunk($splitLength = 1): ArrayObject
    {
        return static::detectArrayType(mb_split($this->string, $splitLength));
    }

    /**
     * @return string
     */
    public function toString()
    {
        return $this->string;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->string;
    }

    /**
     * @param array $value
     * @return ArrayObject
     */
    protected static function detectArrayType(array $value): ArrayObject
    {
        return new ArrayObject($value);
    }
}
