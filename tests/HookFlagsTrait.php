<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests;

use Swoole\Runtime;

trait HookFlagsTrait
{
    protected static int $flags;

    public static function setHookFlags(int $flags = SWOOLE_HOOK_ALL): void
    {
        Runtime::setHookFlags($flags);
    }

    public static function saveHookFlags(): void
    {
        self::$flags = Runtime::getHookFlags();
    }

    public static function restoreHookFlags(): void
    {
        self::setHookFlags(self::$flags);
    }
}
