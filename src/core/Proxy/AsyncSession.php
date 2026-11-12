<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Proxy;

use Swoole\Async\Client;

/**
 * One accepted connection and the backend client it owns.
 *
 * $closing is local to this object. It must not be stored under the backend socket number:
 * that number is shared by every client that has not finished connecting.
 */
final class AsyncSession
{
    public string $pending = '';

    public bool $closing = false;

    public bool $started = false;

    public bool $finished = false;

    public bool $paused = false;

    public function __construct(public readonly Client $client)
    {
    }
}
