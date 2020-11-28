<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$table = swoole_table(100, 'a:f, b:i, c: s:600, d : f');
var_dump($table);

$table = swoole_table(100, 'a: float, b: int, c: string:600, d : float');
var_dump($table);
