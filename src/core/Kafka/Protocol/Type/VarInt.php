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

use Swoole\Kafka\Protocol\ProtocolUtil;

class VarInt extends AbstractType
{
    public const MIN_VALUE = -2147483648;

    public const MAX_VALUE = 2147483647;

    private function __construct()
    {
    }

    public static function pack(int $value): string
    {
        return UVarInt::pack(($value << 1) ^ ($value >> 31));
    }

    public static function unpack(string $value, ?int &$size = null): int
    {
        $result = UVarInt::unpack($value, $size);

        return ProtocolUtil::shr32($result, 1) ^ -($result & 1);
    }
}
