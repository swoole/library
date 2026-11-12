<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc\Example\Etcd;

/** A single key change delivered by a watch stream. */
final class WatchEvent
{
    public const PUT = 0;

    public const DELETE = 1;

    private string $key;

    private string $value;

    private int $type;

    private int $modRevision;

    public function __construct(string $key, string $value, int $type, int $modRevision)
    {
        $this->key         = $key;
        $this->value       = $value;
        $this->type        = $type;
        $this->modRevision = $modRevision;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function isPut(): bool
    {
        return $this->type === self::PUT;
    }

    public function isDelete(): bool
    {
        return $this->type === self::DELETE;
    }

    public function getModRevision(): int
    {
        return $this->modRevision;
    }
}
