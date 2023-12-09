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

use PDO;
use Swoole\ConnectionPool;

/**
 * @method \PDO|PDOProxy get()
 * @method void put(PDO|PDOProxy $connection)
 */
class PDOPool extends ConnectionPool
{
    public function __construct(protected PDOConfig $config, int $size = self::DEFAULT_SIZE)
    {
        parent::__construct(function () {
            $driver = $this->config->getDriver();
            return new \PDO(
                "{$driver}:" .
                (
                    $this->config->hasUnixSocket() ?
                    "unix_socket={$this->config->getUnixSocket()};" :
                    "host={$this->config->getHost()};port={$this->config->getPort()};"
                ) .
                "dbname={$this->config->getDbname()};" .
                (
                    ($driver !== 'pgsql') ?
                    "charset={$this->config->getCharset()}" : ''
                ),
                $this->config->getUsername(),
                $this->config->getPassword(),
                $this->config->getOptions()
            );
        }, $size, PDOProxy::class);
    }
}
