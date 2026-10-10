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

use Swoole\Thread\Runnable;

class IndexedPoolWorker extends Runnable
{
    public function run(array $args): void
    {
        $state = $args[0];
        if ($state->incr('worker_' . $this->id) === 1) {
            return;
        }

        // Every worker must be replaced before any of them stops the pool.
        do {
            $ready = true;
            foreach (range(0, 2) as $index) {
                if (($state['worker_' . $index] ?? 0) < 2) {
                    $ready = false;
                    break;
                }
            }
            if (!$ready) {
                usleep(1000);
            }
        } while (!$ready);

        $state['recovered'] = true;
        $this->shutdown();
    }
}
