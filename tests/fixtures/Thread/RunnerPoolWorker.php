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

class RunnerPoolWorker extends Runnable
{
    public function run(array $args): void
    {
        [$state, $name]           = $args;
        $runner                   = get_included_files()[0];
        $state[$name]             = $runner;
        $state[$name . '_exists'] = is_file($runner);
        $state[$name . '_cwd']    = getcwd();
        $this->shutdown();
    }
}
