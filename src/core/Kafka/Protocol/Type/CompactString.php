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

class CompactString extends AbstractType
{
    private function __construct()
    {
    }

    public static function pack(string $value): string
    {
        return UVarInt::pack(\strlen($value) + 1) . $value;
    }

    public static function unpack(string $value, ?int &$size = null): string
    {
        $length = UVarInt::unpack($value, $size) - 1;
        $result = substr($value, $size, $length);
        $size += $length;

        return $result;
    }
}
