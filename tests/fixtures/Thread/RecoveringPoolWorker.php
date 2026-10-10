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

$arguments = Thread::getArguments();
if ($arguments !== null && $arguments[6]['attempts'] === 1) {
    if ($arguments[7] === 'bootstrap_exception') {
        throw new \RuntimeException('bootstrap failed');
    }
    if ($arguments[7] === 'bootstrap_exit') {
        exit(23);
    }
}

class RecoveringPoolWorker extends Runnable
{
    public function __construct(Atomic $running, int $index)
    {
        parent::__construct($running, $index);
        $arguments = Thread::getArguments();
        if ($arguments[6]['attempts'] === 1 && $arguments[7] === 'constructor_exception') {
            throw new \RuntimeException('constructor failed');
        }
    }

    public function run(array $args): void
    {
        [$state, $mode] = $args;
        if ($state['attempts'] === 1) {
            switch ($mode) {
                case 'run_exception':
                    throw new \RuntimeException('run failed');
                case 'exit_zero':
                    exit(0);
                case 'exit_nonzero':
                    exit(23);
                case 'fatal_error':
                    trigger_error('worker fatal error', E_USER_ERROR);
            }
        }
        $state['recovered'] = true;
        $this->shutdown();
    }
}
