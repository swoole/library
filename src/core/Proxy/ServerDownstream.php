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

use Swoole\Server;

/**
 * TcpDownstream backed by a Swoole server connection.
 */
final class ServerDownstream implements TcpDownstream
{
    public function __construct(private readonly Server $server)
    {
    }

    public function send(int $fd, string $data): bool
    {
        return $this->server->exist($fd) && $this->server->send($fd, $data);
    }

    public function close(int $fd): void
    {
        if ($this->server->exist($fd)) {
            $this->server->close($fd);
        }
    }

    public function pause(int $fd): void
    {
        if ($this->server->exist($fd)) {
            $this->server->pause($fd);
        }
    }

    public function resume(int $fd): void
    {
        if ($this->server->exist($fd)) {
            $this->server->resume($fd);
        }
    }
}
