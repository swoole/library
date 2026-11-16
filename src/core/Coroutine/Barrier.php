<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine;

use Swoole\Coroutine;
use Swoole\Exception;
use Swoole\Timer;

class Barrier
{
    private ?object $waitState = null;

    public function __destruct()
    {
        $this->waitState?->release();
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * @param-out null $barrier
     */
    public static function wait(Barrier &$barrier, float $timeout = -1): void
    {
        if ($barrier->waitState !== null) {
            throw new Exception('The barrier is waiting, cannot wait again.');
        }
        if (!is_finite($timeout)) {
            throw new \InvalidArgumentException('Barrier timeout must be finite');
        }
        // Keep the wait state separate: retaining the barrier itself here would prevent its destruction.
        $state = $barrier->waitState = new class(Coroutine::getCid()) {
            public ?int $timer = null;

            private bool $waiting = false;

            private bool $released = false;

            private bool $finished = false;

            public function __construct(private readonly int $cid)
            {
            }

            public function release(): void
            {
                if ($this->finished) {
                    return;
                }
                $this->released = true;
                if ($this->waiting) {
                    Coroutine::resume($this->cid);
                }
            }

            public function wait(): void
            {
                if (!$this->released) {
                    $this->waiting = true;
                    Coroutine::yield();
                }
            }

            public function finish(): void
            {
                $this->finished = true;
                $this->waiting  = false;
                if ($this->timer !== null && Timer::exists($this->timer)) {
                    Timer::clear($this->timer);
                }
            }
        };
        try {
            if ($timeout > 0) {
                $state->timer = Timer::after(max(1, (int) ceil($timeout * 1000)), $state->release(...));
            }
            $barrier = null;
            $state->wait();
        } finally {
            $state->finish();
        }
    }
}
