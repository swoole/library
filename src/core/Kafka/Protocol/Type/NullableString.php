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

class NullableString extends AbstractType
{
    private function __construct()
    {
    }

    public static function pack(?string $value): string
    {
        if ($value === null) {
            $length = -1;
        } else {
            $length = \strlen($value);
        }

        return Int16::pack($length) . $value;
    }

    public static function unpack(string $value, ?int &$size = null): ?string
    {
        $length = Int16::unpack($value, $size);
        if ($length === -1) {
            return null;
        }
        $result = substr($value, $size, $length);
        $size += $length;

        return $result;
    }
}
