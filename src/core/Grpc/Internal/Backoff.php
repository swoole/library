<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc\Internal;

use Swoole\Coroutine;

/**
 * Exponential backoff with jitter for reconnects, mirroring grpc-go's connection backoff.
 * Pure computation (delayFor) is separated from the actual sleep so it is fully unit-testable.
 */
final class Backoff
{
    private float $initial;

    private float $multiplier;

    private float $max;

    private float $jitter;

    private int $attempt = 0;

    public function __construct(float $initial = 0.1, float $multiplier = 2.0, float $max = 5.0, float $jitter = 0.2)
    {
        $this->initial    = max(0.0, $initial);
        $this->multiplier = max(1.0, $multiplier);
        $this->max        = max($this->initial, $max);
        $this->jitter     = min(1.0, max(0.0, $jitter));
    }

    /**
     * The delay (seconds) for a zero-based attempt number, capped at the max and with jitter.
     */
    public function delayFor(int $attempt): float
    {
        $delay = $this->initial;
        for ($i = 0; $i < $attempt; $i++) {
            $delay *= $this->multiplier;
            if ($delay >= $this->max) {
                break;
            }
        }
        $delay = min($delay, $this->max);

        if ($this->jitter > 0) {
            // Uniform jitter in [1-jitter, 1+jitter].
            $factor = 1.0 - $this->jitter + (mt_rand() / mt_getrandmax()) * 2 * $this->jitter;
            $delay *= $factor;
        }

        return max(0.0, $delay);
    }

    /**
     * Sleep for the current attempt's delay, then advance the attempt counter. Coroutine-aware.
     */
    public function sleep(): void
    {
        $delay = $this->delayFor($this->attempt);
        $this->attempt++;
        if ($delay <= 0) {
            return;
        }
        if (Coroutine::getCid() > 0) {
            Coroutine::sleep($delay);
        } else {
            usleep((int) ($delay * 1_000_000));
        }
    }

    public function reset(): void
    {
        $this->attempt = 0;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }
}
