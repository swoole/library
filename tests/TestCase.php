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

use Deminy\Counit\TestCase as CounitTestCase;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

/**
 * Base class of the library's tests.
 *
 * It extends counit's test case so that, under ./vendor/bin/counit, every test method runs in its own
 * coroutine and time/IO bound tests overlap. Under plain ./vendor/bin/phpunit counit's test case falls
 * back to PHPUnit's behavior, which is what the Build Swoole workflow runs the embedded library with.
 *
 * @internal
 * @coversNothing
 */
abstract class TestCase extends CounitTestCase
{
    /**
     * Runs $fn in a coroutine context, returning only once every coroutine it started has finished.
     *
     * Outside a coroutine this is Swoole\Coroutine\run(). Under counit the whole run already sits
     * inside a coroutine, where a second scheduler cannot be started ("Unable to call Event::wait()
     * in coroutine"), so $fn is called directly and the coroutines it spawned are awaited here
     * instead -- that wait is the part of run()'s contract the tests rely on, e.g. to read what a
     * child coroutine collected once $fn has returned.
     */
    public static function coRun(callable $fn, mixed ...$args): void
    {
        if (Coroutine::getCid() === -1) {
            run($fn, ...$args);
            return;
        }

        $descendants = [Coroutine::getCid() => true];

        try {
            $fn(...$args);
        } finally {
            while (self::hasRunningDescendants($descendants)) {
                Coroutine::sleep(0.001);
            }
        }
    }

    /**
     * Whether any coroutine started by the current coroutine is still running.
     *
     * @param array<int, true> $descendants Coroutines already known to belong to this test, keyed by
     *                                      cid. Cids stay in it once seen, so that a coroutine whose
     *                                      own parent has already finished is still recognized.
     */
    private static function hasRunningDescendants(array &$descendants): bool
    {
        $self    = Coroutine::getCid();
        $running = false;

        foreach (Coroutine::listCoroutines() as $cid) {
            if (isset($descendants[$cid])) {
                $running = $running || ($cid !== $self);
                continue;
            }

            $pcid = Coroutine::getPcid($cid);
            if (is_int($pcid) && isset($descendants[$pcid])) {
                $descendants[$cid] = true;
                $running           = true;
            }
        }

        return $running;
    }
}
