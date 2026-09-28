<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Tests\TestThread;
use Swoole\Thread\Pool;

require_once dirname(__DIR__) . '/bootstrap.php';

$map = new Swoole\Thread\Map();

// The threads run until one of them has counted far enough and shuts the pool down.
(new Pool(TestThread::class, 4))
    ->withClassDefinitionFile(dirname(__DIR__, 2) . '/tests/TestThread.php')
    ->withArguments(uniqid(), $map)
    ->start()
;

echo "Threads started: {$map['thread']}\n";
echo "Steps counted: {$map['sleep']}\n";
