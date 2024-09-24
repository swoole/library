<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Thread\Pool;
use tests\TestThread;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$map = new Swoole\Thread\Map();

(new Pool(TestThread::class, 4))
    ->withAutoloader(dirname(__DIR__, 2) . '/vendor/autoload.php')
    ->withClassDefinitionFile(__DIR__ . '/TestThread.php')
    ->withArguments([uniqid(), $map])
    ->start()
;
