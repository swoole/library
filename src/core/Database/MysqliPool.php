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

use Swoole\ConnectionPool;

/**
 * @method \mysqli|MysqliProxy|false get(float $timeout = -1)
 */
class MysqliPool extends ConnectionPool
{
    public function __construct(protected MysqliConfig $config, int $size = self::DEFAULT_SIZE)
    {
        parent::__construct(function () {
            $mysqli = new \mysqli();
            foreach ($this->config->getOptions() as $option => $value) {
                $mysqli->set_opt($option, $value);
            }
            $mysqli->real_connect(
                $this->config->getHost(),
                $this->config->getUsername(),
                $this->config->getPassword(),
                $this->config->getDbname(),
                $this->config->getPort(),
                $this->config->getUnixSocket()
            );
            if ($mysqli->connect_errno) {
                throw new MysqliException($mysqli->connect_error, $mysqli->connect_errno);
            }
            $mysqli->set_charset($this->config->getCharset());
            return $mysqli;
        }, $size, MysqliProxy::class);
    }

    /**
     * Get a mysqli connection from the pool, wrapped in a MysqliProxy. The proxy's transaction tracking is reset,
     * as PDOPool does, so that a connection put back in the middle of a transaction does not keep the next
     * borrower from reconnecting when the connection is lost.
     *
     * @param float $timeout > 0 means waiting for the specified number of seconds. other means no waiting.
     * @return MysqliProxy|false Returns a MysqliProxy object from the pool, or false if the pool is full and the timeout is reached.
     */
    public function get(float $timeout = -1)
    {
        /* @var MysqliProxy|false $mysqli */
        $mysqli = parent::get($timeout);
        if ($mysqli === false) {
            return false;
        }

        $mysqli->reset();

        return $mysqli;
    }

    /**
     * Return a connection to the pool.
     *
     * A transaction left open on the connection is rolled back first, and autocommit is turned back on, so that
     * the next borrower does not work inside a transaction it never began. A connection that cannot be cleaned
     * is replaced; when the replacement cannot be made, it is left to the next get() to make it and to report
     * the failure.
     *
     * The transaction is the one the proxy tracks, see MysqliProxy::inTransaction(): one started by hand, with
     * query('START TRANSACTION') or query('SET autocommit=0'), is not seen and stays open.
     *
     * @param MysqliProxy|null $connection the connection to return, or null to have a broken connection replaced
     */
    public function put(mixed $connection): void
    {
        if ($connection instanceof MysqliProxy && !$this->clean($connection)) {
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
     * Rolls back the transaction left open on the connection, if any, and turns autocommit back on.
     *
     * @return bool false when that fails, as when the connection was lost, or closed, inside the transaction
     */
    private function clean(MysqliProxy $connection): bool
    {
        if ($connection->inTransaction()) {
            $mysqli = $connection->__getObject();
            try {
                if (!@$mysqli->rollback() || !@$mysqli->autocommit(true)) {
                    return false;
                }
            } catch (\Throwable) {
                // A mysqli_sql_exception, or the Error of a connection that was closed.
                return false;
            }
            $connection->reset();
        }

        return true;
    }
}
