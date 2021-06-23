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
use PDOException;
use PDOStatement;

class PDOStatementProxy extends ObjectProxy
{
    /** @var PDOStatement */
    protected $__object;

    /** @var null|array */
    protected $setAttributeContext;

    /** @var null|array */
    protected $setFetchModeContext;

    /** @var null|array */
    protected $bindParamContext;

    /** @var null|array */
    protected $bindColumnContext;

    /** @var null|array */
    protected $bindValueContext;

    /** @var PDO|PDOProxy */
    protected $parent;

    /** @var int */
    protected $parentRound;

    public function __construct(PDOStatement $object, PDOProxy $parent)
    {
        parent::__construct($object);
        $this->parent = $parent;
        $this->parentRound = $parent->getRound();
    }

    public function __call(string $name, array $arguments)
    {
        for ($n = 3; $n--;) {
            $ret = @$this->__object->{$name}(...$arguments);
            if ($ret === false) {
                /* no IO */
                if (strtolower($name) !== 'execute') {
                    break;
                }
                /* no more chances or non-IO failures or in transaction */
                if (
                    !in_array($this->__object->errorInfo()[1], $this->parent::IO_ERRORS, true)
                    || $n === 0
                    || $this->parent->inTransaction()
                ) {
                    $errorInfo = $this->__object->errorInfo();

                    /* '00000' means “no error.”, as specified by ANSI SQL and ODBC. */
                    if (!empty($errorInfo) && $errorInfo[0] !== '00000') {
                        $exception = new PDOException($errorInfo[2], $errorInfo[1]);
                        $exception->errorInfo = $errorInfo;
                        throw $exception;
                    }
                    /* no error info, just return false */
                    break;
                }
                if ($this->parent->getRound() === $this->parentRound) {
                    /* if not equal, parent has reconnected */
                    $this->parent->reconnect();
                }
                $parent = $this->parent->__getObject();
                $this->__object = $parent->prepare($this->__object->queryString);
                if ($this->__object === false) {
                    $errorInfo = $parent->errorInfo();
                    $exception = new PDOException($errorInfo[2], $errorInfo[1]);
                    $exception->errorInfo = $errorInfo;
                    throw $exception;
                }
                if ($this->setAttributeContext) {
                    foreach ($this->setAttributeContext as $attribute => $value) {
                        $this->__object->setAttribute($attribute, $value);
                    }
                }
                if ($this->setFetchModeContext) {
                    $this->__object->setFetchMode(...$this->setFetchModeContext);
                }
                if ($this->bindParamContext) {
                    foreach ($this->bindParamContext as $param => $item) {
                        $this->__object->bindParam($param, ...$item);
                    }
                }
                if ($this->bindColumnContext) {
                    foreach ($this->bindColumnContext as $column => $item) {
                        $this->__object->bindColumn($column, ...$item);
                    }
                }
                if ($this->bindValueContext) {
                    foreach ($this->bindValueContext as $value => $item) {
                        $this->__object->bindParam($value, ...$item);
                    }
                }
                continue;
            }
            break;
        }
        /* @noinspection PhpUndefinedVariableInspection */
        return $ret;
    }

    public function setAttribute(int $attribute, $value): bool
    {
        $this->setAttributeContext[$attribute] = $value;
        return $this->__object->setAttribute($attribute, $value);
    }

    /**
     * Reference: https://www.php.net/manual/en/pdostatement.setfetchmode.php
     *
     * ALT approaches:
     *   - setFetchMode(int $mode, $colno_class_object = null, array $constructorArgs = [])
     *     - 2nd parameter can not be type-hinted to pass coding style check
     *   - creating separate methods for each mode is too verbose
     *     - setFetchMode
     *     - setFetchModeByColumn
     *     - setFetchModeByClass
     *     - setFetchModeByObject
     */
    public function setFetchMode(int $mode, ...$args): bool
    {
        if ($mode == PDO::FETCH_COLUMN) {
            if (empty($args)) {
                throw new \Exception("2nd parameter \"colno\" is missing");
            }
            list($colno) = $args;
            $this->setFetchModeContext = [$mode, (int)$colno];
            return $this->__object->setFetchMode($mode, (int)$colno);
        }

        if ($mode == PDO::FETCH_CLASS) {
            if (count($args) >= 2) {
                list($class, $constructorArgs) = $args;
                if (!is_null($constructorArgs) && !is_array($constructorArgs)) {
                    throw new \Exception("3rd parameter \"constructArgs\" must be NULL or Array");
                }
            }
            elseif (count($args) == 1) {
                list($class) = $args;
                $constructorArgs = NULL; // NULL|array
            }
            else {
                throw new \Exception("2nd parameter \"class\" is missing");
            }
            if (empty($class) || !class_exists($class)) {
                throw new \Exception("2nd parameter must be valid class for setFetchMode(FETCH_CLASS, class, constructorArgs)");
            }
            $this->setFetchModeContext = [$mode, $class, $constructorArgs];
            return $this->__object->setFetchMode($mode, $class, $constructorArgs);
        }

        if ($mode == PDO::FETCH_INTO) {
            if (empty($args)) {
                throw new \Exception("2nd parameter \"object\" is missing");
            }
            list($object) = $args;
            if (! is_object($object)) {
                throw new \Exception("2nd parameter must be object for setFetchMode(FETCH_INTO, object)");
            }
            $this->setFetchModeContext = [$mode, $object];
            return $this->__object->setFetchMode($mode, $object);
        }

        return $this->__object->setFetchMode($mode);
    }

    public function bindParam($parameter, &$variable, $data_type = PDO::PARAM_STR, $length = null, $driver_options = null): bool
    {
        $this->bindParamContext[$parameter] = [$variable, $data_type, $length, $driver_options];
        return $this->__object->bindParam($parameter, $variable, $data_type, $length, $driver_options);
    }

    public function bindColumn($column, &$param, $type = null, $maxlen = null, $driverdata = null): bool
    {
        $this->bindColumnContext[$column] = [$param, $type, $maxlen, $driverdata];
        return $this->__object->bindColumn($column, $param, $type, $maxlen, $driverdata);
    }

    public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR): bool
    {
        $this->bindValueContext[$parameter] = [$value, $data_type];
        return $this->__object->bindValue($parameter, $value, $data_type);
    }
}
