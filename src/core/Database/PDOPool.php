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

class PDOPool extends ConnectionPool
{
    public function __construct(protected PDOConfig $config, int $size = self::DEFAULT_SIZE)
    {
        parent::__construct(function () {
            $driver = $this->config->getDriver();
            if ($driver === 'sqlite') {
                return new \PDO($this->createDSN('sqlite'));
            }

            return new \PDO($this->createDSN($driver), $this->config->getUsername(), $this->config->getPassword(), $this->config->getOptions());
        }, $size, PDOProxy::class);
    }

    /**
     * Get a PDO connection from the pool. The PDO connection (a PDO object) is wrapped in a PDOProxy object returned.
     *
     * @param float $timeout the number of seconds to wait for a connection; 0 or less waits with no time limit
     * @return PDOProxy|false Returns a PDOProxy object from the pool, or false if the pool is full and the timeout is reached.
     *                        {@inheritDoc}
     */
    public function get(float $timeout = -1)
    {
        /* @var \Swoole\Database\PDOProxy|false $pdo */
        $pdo = parent::get($timeout);
        if ($pdo === false) {
            return false;
        }

        $pdo->reset();

        return $pdo;
    }

    /**
     * Return a connection to the pool.
     *
     * A transaction left open on the connection is rolled back first, so that the next borrower does not work
     * inside a transaction it never began. On MySQL and Oracle, autocommit is set back to the value of the pool's
     * options, on by default. A connection that cannot be cleaned is replaced; when the replacement cannot be made,
     * it is left to the next get() to make it and to report the failure.
     *
     * The transaction is the one the driver reports. pdo_mysql and pdo_pgsql report a transaction started by
     * hand, e.g. with exec('BEGIN'), too, and so does pdo_sqlite as of PHP 8.4 or with Swoole's coroutine SQLite.
     * The other drivers only report one started with beginTransaction().
     *
     * @param PDOProxy|null $connection the connection to return, or null to have a broken connection replaced
     */
    public function put(mixed $connection): void
    {
        if ($connection instanceof PDOProxy && !$this->clean($connection)) {
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
     * Rolls back the transaction left open on the connection, if any, and sets autocommit back.
     *
     * @return bool false when that fails, as when the connection was lost inside the transaction
     */
    private function clean(PDOProxy $connection): bool
    {
        $pdo = $connection->__getObject();
        // The state PDO reports now, not the one the proxy recorded: a connection that was lost reads as inside a
        // transaction, the rollback fails, and the connection is replaced.
        if ($pdo->inTransaction()) {
            try {
                if (!$pdo->rollBack()) {
                    return false;
                }
            } catch (\PDOException) {
                return false;
            }
        }
        // pdo_oci reports a transaction only after beginTransaction(). Work done with autocommit off is not seen, and
        // would be committed by the first statement of the next borrower, once autocommit is on again.
        if ($this->config->getDriver() === 'oci') {
            try {
                if (!$pdo->getAttribute(\PDO::ATTR_AUTOCOMMIT) && $pdo->exec('ROLLBACK') === false) {
                    return false;
                }
            } catch (\PDOException) {
                return false;
            }
        }
        // Autocommit turned off on the connection, e.g. with setAttribute(PDO::ATTR_AUTOCOMMIT, false), would leave
        // the next borrower inside a transaction from its first statement on. Through the proxy, so that a reconnect
        // restores the same value.
        if (in_array($this->config->getDriver(), ['mysql', 'oci'], true)) {
            $autocommit = (bool) ($this->config->getOptions()[\PDO::ATTR_AUTOCOMMIT] ?? true);
            try {
                if ((bool) $pdo->getAttribute(\PDO::ATTR_AUTOCOMMIT) !== $autocommit && !$connection->setAttribute(\PDO::ATTR_AUTOCOMMIT, $autocommit)) {
                    return false;
                }
            } catch (\PDOException) {
                return false;
            }
        }
        $connection->reset();

        return true;
    }

    /**
     * @purpose create DSN
     * @throws \Exception
     */
    private function createDSN(string $driver): string
    {
        switch ($driver) {
            case 'mysql':
                if ($this->config->hasUnixSocket()) {
                    $dsn = "mysql:unix_socket={$this->config->getUnixSocket()};dbname={$this->config->getDbname()};charset={$this->config->getCharset()}";
                } else {
                    $dsn = "mysql:host={$this->config->getHost()};port={$this->config->getPort()};dbname={$this->config->getDbname()};charset={$this->config->getCharset()}";
                }
                break;
            case 'pgsql':
                $dsn = 'pgsql:host=' . ($this->config->hasUnixSocket() ? $this->config->getUnixSocket() : $this->config->getHost()) . ";port={$this->config->getPort()};dbname={$this->config->getDbname()}";
                break;
            case 'oci':
                $dsn = 'oci:dbname=' . ($this->config->hasUnixSocket() ? $this->config->getUnixSocket() : $this->config->getHost()) . ':' . $this->config->getPort() . '/' . $this->config->getDbname() . ';charset=' . $this->config->getCharset();
                break;
            case 'sqlite':
                // There are three types of SQLite databases: databases on disk, databases in memory, and temporary
                // databases (which are deleted when the connections are closed). It doesn't make sense to use
                // connection pool for the latter two types of databases, because each connection connects to a
                //different in-memory or temporary SQLite database.
                if ($this->config->getDbname() === '') {
                    throw new \Exception('Connection pool in Swoole does not support temporary SQLite databases.');
                }
                if ($this->config->getDbname() === ':memory:') {
                    throw new \Exception('Connection pool in Swoole does not support creating SQLite databases in memory.');
                }
                $dsn = 'sqlite:' . $this->config->getDbname();
                break;
            default:
                throw new \Exception('Unsupported Database Driver:' . $driver);
        }
        return $dsn;
    }
}
