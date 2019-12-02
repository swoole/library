<?php
declare(strict_types=1);

namespace Swoole\Database;

use Redis;
use Swoole\ConnectionPool;

/**
 * @method Redis get()
 * @method void put(Redis $connection)
 */
class RedisPool extends ConnectionPool
{
    /** @var int */
    protected $size = 64;
    /** @var RedisConfig */
    protected $config;

    public function __construct(RedisConfig $config, int $size = 64)
    {
        $this->config = $config;
        parent::__construct(function () {
            $redis = new Redis;
            $redis->connect(
                $this->config->getHost(),
                $this->config->getPort(),
                $this->config->getTimeout(),
                $this->config->getReserved(),
                $this->config->getRetryInterval(),
                $this->config->getReadTimeout()
            );
            return $redis;
        }, $size);
    }
}
