<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Future;

use Swoole\Future;

function async(callable $func): Future
{
    return Future::create($func);
}

function join(...$futures): array
{
    return Future::join($futures);
}
