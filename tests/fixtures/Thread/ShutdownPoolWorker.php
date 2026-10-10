<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Fixtures;

use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Runnable;

class ShutdownPoolWorker extends Runnable
{
    public function __construct(Atomic $running, int $index)
    {
        parent::__construct($running, $index);
        $arguments = Thread::getArguments();
        $arguments[6]->incr('constructed');
        if ($arguments[7] === 'shutdown_during_startup') {
            $this->shutdown();
            $arguments[6]['stopped'] = true;
        }
    }

    public function run(array $args): void
    {
        $args[0]->incr('runs');
        if ($args[1] === 'shutdown') {
            // Let the manager enter its completion wait before requesting shutdown.
            usleep(50000);
            $this->shutdown();
        }
    }
}
