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

class Float64 extends AbstractType
{
    public const FORAMT = 'E';

    private function __construct()
    {
    }

    public static function pack(float $value): string
    {
        return pack(self::FORAMT, $value);
    }

    public static function unpack(string $value, ?int &$size = null): float
    {
        $size = 8;

        return unpack(self::FORAMT, $value)[1];
    }
}
