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

const IGNIRE_ARGS = true;
const MAX_CALL_STACK = 32;
const MAX_DETECT_CO = 32;

function batch(array $tasks, float $timeout = -1): array
{
    $wg = new WaitGroup(count($tasks));
    foreach ($tasks as $id => $task) {
        Coroutine::create(function () use ($wg, &$tasks, $id, $task) {
            $tasks[$id] = null;
            $tasks[$id] = $task();
            $wg->done();
        });
    }
    $wg->wait($timeout);
    return $tasks;
}

function parallel(int $n, callable $fn): void
{
    $count = $n;
    $wg = new WaitGroup($n);
    while ($count--) {
        Coroutine::create(function () use ($fn, $wg) {
            $fn();
            $wg->done();
        });
    }
    $wg->wait();
}

function map(array $list, callable $fn, float $timeout = -1): array
{
    $wg = new WaitGroup(count($list));
    foreach ($list as $id => $elem) {
        Coroutine::create(function () use ($wg, &$list, $id, $elem, $fn): void {
            $list[$id] = null;
            $list[$id] = $fn($elem);
            $wg->done();
        });
    }
    $wg->wait($timeout);
    return $list;
}

function deadlock_check()
{
    $all_coroutines = Coroutine::listCoroutines();
    $count = Coroutine::stats()['coroutine_num'];
    echo
    "\n===================================================================",
    "\n [FATAL ERROR]: all coroutines (count: {$count}) are asleep - deadlock!",
    "\n===================================================================\n";

    $index = 0;
    foreach ($all_coroutines as $cid) {
        echo "\n [Coroutine-$cid]";
        echo "\n--------------------------------------------------------------------\n";
        echo Coroutine::printBackTrace($cid, IGNIRE_ARGS ? DEBUG_BACKTRACE_IGNORE_ARGS : 0, MAX_CALL_STACK);
        echo "\n";

        //limit the number of maximum outputs
        if ($index > MAX_DETECT_CO) {
            break;
        }
    }
}
