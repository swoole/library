<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Timer;

class NoopTimer implements TimerInterface
{
    public function tick(int $interval, callable $callback): int
    {
        return 0;
    }

    public function clear(int $timerId): void
    {
    }
}
