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

/**
 * The accepted side of an event-driven TCP proxy. Implementations talk to one connection id.
 */
interface TcpDownstream
{
    public function send(int $fd, string $data): bool;

    public function close(int $fd): void;

    /**
     * Stop reading $fd. Used when the backend send buffer is full.
     */
    public function pause(int $fd): void;

    /**
     * Read $fd again after pause().
     */
    public function resume(int $fd): void;
}
