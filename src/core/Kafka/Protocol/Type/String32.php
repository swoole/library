<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\Type;

class String32 extends AbstractType
{
    private function __construct()
    {
    }

    public static function pack(string $value): string
    {
        return Int32::pack(\strlen($value)) . $value;
    }

    public static function unpack(string $value, ?int &$size = null): string
    {
        $length = Int32::unpack(substr($value, 0, 4), $size);
        $result = substr($value, $size, $length);
        $size += $length;

        return $result;
    }
}
