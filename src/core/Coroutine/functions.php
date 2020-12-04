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

function get_debug_print_backtrace($traces, $traces_to_ignore = 1)
{
    $ret = array();
    foreach ($traces as $i => $call) {
        if ($i < $traces_to_ignore) {
            continue;
        }

        $object = '';
        if (isset($call['class'])) {
            $object = $call['class'] . $call['type'];
            if (is_array($call['args'])) {
                foreach ($call['args'] as &$arg) {
                    get_arg($arg);
                }
            }
        }
        $ret[] = '#' . str_pad(strval($i - $traces_to_ignore), 3, ' ')
            . $object . $call['function'] . '(' . implode(', ', $call['args'])
            . ') called at [' . $call['file'] . ':' . $call['line'] . ']';
    }

    return implode("\n", $ret);
}

function get_arg(&$arg)
{
    if (is_object($arg)) {
        $arr = (array)$arg;
        $args = array();
        foreach ($arr as $key => $value) {
            if (strpos($key, chr(0)) !== false) {
                $key = '';    // Private variable found
            }
            $args[] = '[' . $key . '] => ' . get_arg($value);
        }

        $arg = get_class($arg) . ' Object (' . implode(',', $args) . ')';
    }
}

function deadlock_detect()
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
        echo get_debug_print_backtrace(Coroutine::getBackTrace($cid));
        echo "\n\n";

        //limit the number of maximum outputs
        if ($index > 32) {
            break;
        }
    }
}
