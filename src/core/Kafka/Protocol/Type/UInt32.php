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

class UInt32 extends AbstractType
{
    public const FORAMT = 'N';

    public const MIN_VALUE = 0;

    public const MAX_VALUE = 4294967295;

    private function __construct()
    {
    }

    public static function pack(int $value): string
    {
        if ($value < self::MIN_VALUE || $value > self::MAX_VALUE) {
            throw new \InvalidArgumentException(sprintf('%s is outside the range of UInt32', $value));
        }

        return pack(self::FORAMT, $value);
    }

    public static function unpack(string $value, ?int &$size = null): int
    {
        $size = 4;

        return unpack(self::FORAMT, $value)[1];
    }
}
