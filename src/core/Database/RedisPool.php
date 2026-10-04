<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Database;

use Redis;
use Swoole\ConnectionPool;

/**
 * @method \Redis|false get(float $timeout = -1)
 */
class RedisPool extends ConnectionPool
{
    public function __construct(protected RedisConfig $config, int $size = self::DEFAULT_SIZE)
    {
        parent::__construct(function () {
            $redis = new \Redis();
            // Every argument is passed, in the position phpredis defines for it: leaving one out shifts the ones
            // after it into the wrong parameters (a read timeout became the connect timeout, a retry interval
            // landed in the persistent id). The zero defaults mean "unset" to phpredis, as they do to the config.
            $redis->connect(
                $this->config->getHost(),
                $this->config->getPort(),
                $this->config->getTimeout(),
                null, // persistent_id: a pooled connection is never persistent
                $this->config->getRetryInterval(),
                $this->config->getReadTimeout()
            );
            if ($this->config->getAuth()) {
                $redis->auth($this->config->getAuth());
            }
            if ($this->config->getDbIndex() !== 0) {
                $redis->select($this->config->getDbIndex());
            }

            /* Set Redis options. */
            foreach ($this->config->getOptions() as $key => $value) {
                $redis->setOption($key, $value);
            }

            return $redis;
        }, $size);
    }

    /**
     * Return a connection to the pool.
     *
     * A connection left in MULTI or pipeline mode, as by a transaction that was never executed, is brought back to
     * the normal mode first, so that the commands of the next borrower are run instead of queued. A connection that
     * cannot be brought back is replaced; when the replacement cannot be made, it is left to the next get() to make
     * it and to report the failure.
     *
     * State the connection does not report is not reset: a WATCH that was not ended with EXEC, DISCARD or UNWATCH, or
     * a database chosen with select().
     *
     * @param \Redis|null $connection the connection to return, or null to have a broken connection replaced
     */
    public function put(mixed $connection): void
    {
        if ($connection instanceof \Redis && !$this->clean($connection)) {
            try {
                parent::put(null);
            } catch (\Throwable) {
                // Putting a connection back is no place to report that a new one cannot be made.
            }
            return;
        }

        parent::put($connection);
    }

    /**
     * Discards a transaction or a pipeline left open on the connection, if any.
     *
     * @return bool false when that fails, as when the connection was lost
     */
    private function clean(\Redis $connection): bool
    {
        try {
            if ($connection->getMode() !== \Redis::ATOMIC) {
                $connection->discard();
            }
            return $connection->getMode() === \Redis::ATOMIC;
        } catch (\RedisException) {
            return false;
        }
    }
}
