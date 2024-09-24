<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Thread;

abstract class Runnable
{
    protected Atomic $running;

    public function __construct($running)
    {
        $this->running = $running;
    }

    abstract public function run(array $args): void;

    protected function isRunning(): bool
    {
        return $this->running->get() === 1;
    }

    protected function shutdown(): void
    {
        $this->running->set(0);
    }
}
