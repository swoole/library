<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

if (SWOOLE_USE_SHORTNAME) {
    function _string(string $string = ''): Swoole\StringObject
    {
        return new Swoole\StringObject($string);
    }

    function _mbstring(string $string = ''): Swoole\MultibyteStringObject
    {
        return new Swoole\MultibyteStringObject($string);
    }

    function _array(array $array = []): Swoole\ArrayObject
    {
        return new Swoole\ArrayObject($array);
    }
}

function swoole_string(string $string = ''): Swoole\StringObject
{
    return new Swoole\StringObject($string);
}

function swoole_mbstring(string $string = ''): Swoole\MultibyteStringObject
{
    return new Swoole\MultibyteStringObject($string);
}

function swoole_array(array $array = []): Swoole\ArrayObject
{
    return new Swoole\ArrayObject($array);
}

function swoole_array_default_value(array $array, $key, $default_value = null)
{
    return array_key_exists($key, $array) ? $array[$key] : $default_value;
}
