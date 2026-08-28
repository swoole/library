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

use Swoole\Coroutine;
use Swoole\Runtime;

trait HookFlagsTrait
{
    protected static $flags;

    /**
     * Hook flags are process-wide. Under counit the tests of a run share one scheduler, whose flags
     * were picked for the run as a whole by Deminy\Counit\Helper::coroutineHookFlags(), so a test
     * changing them here would change them for every test running concurrently with it -- including
     * turning on the STDIO and file hooks counit deliberately leaves off. Inside a coroutine the
     * run's flags therefore stand and this is a no-op.
     */
    public static function setHookFlags(int $flags = SWOOLE_HOOK_ALL): void
    {
        if (Coroutine::getCid() !== -1) {
            return;
        }

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
