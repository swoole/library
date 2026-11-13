<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Socket;

use Swoole\Kafka\Config\CommonConfig;

interface SocketInterface
{
    public function __construct(string $host, int $port, ?CommonConfig $config = null);

    public function getHost(): string;

    public function getPort(): int;

    public function getConfig(): CommonConfig;

    public function isConnected(): bool;

    public function connect(): void;

    public function close(): bool;

    public function send(string $data, ?float $timeout = null): int;

    public function recv(int $length, ?float $timeout = null): string;
}
