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

use CrowdStar\Backoff\AbstractRetryCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use Exception;
use PHPUnit\Framework\Exception as PHPUnitException;

/**
 * Helper for the tests that talk to services beyond the code under test, like the httpbin service of
 * docker-compose.yml.
 *
 * A request that fails for a transient reason — a service still coming up, or a response that is not the
 * expected payload — is a reason to ask again, not to break the build for something that has nothing to do
 * with the code under test.
 */
trait RetryTrait
{
    /**
     * Run $callback again, waiting exponentially longer in between, for as long as it throws an exception.
     *
     * Everything PHPUnit itself throws — a failed assertion above all, which is a
     * \PHPUnit\Framework\ExpectationFailedException and therefore a \PHPUnit\Framework\Exception — is left
     * alone and reported on the first attempt: it says something about the code under test, and retrying it
     * would only hide the failure behind a delay. So keep assertions on a response *outside* of $callback.
     * What belongs inside is the request itself, plus whatever it takes to tell a usable response from one
     * worth asking for again; the latter signals by throwing an ordinary exception.
     *
     * The backoff waits ~0.25s, ~0.5s and ~1s before the second, third and fourth attempt, and it does so
     * without blocking the scheduler when called from inside a coroutine. The last exception is rethrown once
     * the attempts run out, so a service that is genuinely broken still fails the test.
     */
    protected static function retry(\Closure $callback, int $maxAttempts = 4): mixed
    {
        $condition = new class extends AbstractRetryCondition {
            public function met($result, ?\Exception $e): bool
            {
                return ($e === null) || ($e instanceof PHPUnitException);
            }
        };

        return (new ExponentialBackoff($condition))
            ->setMaxAttempts($maxAttempts)
            ->run($callback)
        ;
    }
}
