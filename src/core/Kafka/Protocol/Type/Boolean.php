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

class Boolean extends AbstractType
{
    public const FORAMT = 'c';

    private function __construct()
    {
    }

    public static function pack(bool $value): string
    {
        return pack(self::FORAMT, $value);
    }

    public static function unpack(string $value, ?int &$size = null): bool
    {
        $size = 1;

        return unpack(self::FORAMT, $value)[1] === 1;
    }
}
