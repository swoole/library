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

/**
 * The proxy class for PHP class PDOStatement.
 *
 * @see https://www.php.net/PDOStatement The PDOStatement class
 */
class PDOStatementProxy extends ObjectProxy
{
    /** @var \PDOStatement */
    protected $__object;

    protected array $setAttributeContext = [];

    protected array $setFetchModeContext = [];

    protected array $bindParamContext = [];

    protected array $bindColumnContext = [];

    protected array $bindValueContext = [];

    /** @var int */
    protected $parentRound;

    public function __construct(\PDOStatement $object, protected PDOProxy $parent)
    {
        parent::__construct($object);
        $this->parentRound = $parent->getRound();
    }

    public function __call(string $name, array $arguments)
    {
        // Not the state PDO reports now, which a connection found dead makes "inside a transaction" on PostgreSQL, but
        // the one the parent recorded, which includes a transaction started by hand. A connection known to be lost is
        // in no transaction any more: that one went down with it, and was reported.
        $inTransaction = $this->parent->hasOpenTransaction();
        try {
            $ret = $this->__object->{$name}(...$arguments);
        } catch (\PDOException $e) {
            if ($inTransaction && DetectsLostConnections::causedByLostConnection($e)) {
                // The transaction died with the connection. The caller is told, and the next call reconnects.
                if ($this->parent->getRound() === $this->parentRound) {
                    $this->parent->markAsLost();
                }
                throw $e;
            }
            // Only execute() is retried on a fresh connection, since it runs the statement from the start. The
            // other methods (fetch*(), rowCount(), ...) read the result of an execute() that went down with the
            // connection; re-preparing the statement and calling one of them on it without executing it first
            // returns no rows and no error, so a lost connection there surfaces as the exception it is.
            if (strcasecmp($name, 'execute') === 0 && DetectsLostConnections::causedByLostConnection($e)) {
                // A parent of another round has reconnected already, unless that reconnect failed and left it lost.
                if ($this->parent->getRound() === $this->parentRound || $this->parent->isLost()) {
                    $this->parent->reconnect();
                }
                // Record the parent's round, or the next lost connection on this statement looks like one the parent
                // has already recovered from, and the statement is prepared again on the dead connection instead.
                $this->parentRound = $this->parent->getRound();
                $parent            = $this->parent->__getObject();
                $statement         = $parent->prepare($this->__object->queryString);
                if ($statement === false) {
                    throw $e;
                }
                $this->__object = $statement;

                foreach ($this->setAttributeContext as $attribute => $value) {
                    $this->__object->setAttribute($attribute, $value);
                }
                if (!empty($this->setFetchModeContext)) {
                    $this->__object->setFetchMode(...$this->setFetchModeContext);
                }
                foreach ($this->bindParamContext as $param => $item) {
                    $this->__object->bindParam($param, ...$item);
                }
                foreach ($this->bindColumnContext as $column => $item) {
                    $this->__object->bindColumn($column, ...$item);
                }
                foreach ($this->bindValueContext as $parameter => $item) {
                    $this->__object->bindValue($parameter, ...$item);
                }
                $ret = $this->__object->{$name}(...$arguments);
            } else {
                throw $e;
            }
        }

        return $ret;
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->setAttributeContext[$attribute] = $value;
        return $this->__object->setAttribute($attribute, $value);
    }

    /**
     * Set the default fetch mode for this statement.
     *
     * @see https://www.php.net/manual/en/pdostatement.setfetchmode.php
     */
    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->setFetchModeContext = func_get_args();
        return $this->__object->setFetchMode(...$this->setFetchModeContext);
    }

    public function bindParam($param, &$var, $type = \PDO::PARAM_STR, $maxLength = 0, $driverOptions = null): bool
    {
        // The variable is kept by reference: a statement prepared again after a reconnect is bound to the variable of
        // the caller, as the first one was, and not to the value the variable had at the time of this call.
        $key = self::parameterKey($param);
        unset($this->bindValueContext[$key]);
        $this->bindParamContext[$key] = [&$var, $type, $maxLength, $driverOptions];
        return $this->__object->bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    public function bindColumn($column, &$var, $type = \PDO::PARAM_STR, $maxLength = 0, $driverOptions = null): bool
    {
        $this->bindColumnContext[$column] = [&$var, $type, $maxLength, $driverOptions];
        return $this->__object->bindColumn($column, $var, $type, $maxLength, $driverOptions);
    }

    public function bindValue($param, $value, $type = \PDO::PARAM_STR): bool
    {
        $key = self::parameterKey($param);
        unset($this->bindParamContext[$key]);
        $this->bindValueContext[$key] = [$value, $type];
        return $this->__object->bindValue($param, $value, $type);
    }

    /**
     * The key a parameter is recorded under. A parameter is bound by bindParam() or by bindValue(), whichever was
     * called last, and is recorded once: the bindings are made again by kind after a reconnect, not in the order of
     * the calls. PDO takes a name with or without its leading colon.
     */
    private static function parameterKey(int|string $param): int|string
    {
        return is_string($param) && !str_starts_with($param, ':') ? ':' . $param : $param;
    }
}
