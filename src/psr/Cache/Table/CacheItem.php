<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Psr\Cache\Table;

use Psr\Cache\CacheItemInterface;

class CacheItem implements CacheItemInterface
{
    /**
     * @var string
     */
    private $key;

    /**
     * @var mixed
     */
    private $value;

    /**
     * @var int timestamp
     */
    private $ttl;

    public function __construct(string $key, $value)
    {
        $this->key = $key;
        $this->value = $value;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get()
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return true;
    }

    public function set($value): self
    {
        $this->value = $value;
        return $this;
    }

    public function expiresAt($expiration)
    {
        $this->ttl = $expiration->getTimestamp();
        return $this;
    }

    public function expiresAfter($time)
    {
        $this->ttl = (new \DateTime())->add($time)->getTimestamp();
        return $this;
    }
}
