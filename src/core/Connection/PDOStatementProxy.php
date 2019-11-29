<?php
declare(strict_types=1);

namespace Swoole\Connection;

use PDO;
use PDOException;
use PDOStatement;
use Swoole\Object\Proxy;

class PDOStatementProxy extends Proxy
{
    /** @var PDOStatement */
    protected $object;
    /** @var PDOProxy|PDO */
    protected $parent;
    /** @var int */
    protected $parentRound;

    public function __construct(PDOStatement $object, PDOProxy $parent)
    {
        parent::__construct($object);
        $this->parent = $parent;
        $this->parentRound = $parent->getRound();
    }

    /** @noinspection PhpUnused */
    public function __call(string $name, array $arguments)
    {
        for ($n = 3; $n--;) {
            $ret = @$this->object->$name(...$arguments);
            if ($ret === false) {
                /* no more chances or non-IO failures */
                if ($n === 0 || !in_array($this->object->errorInfo()[1], $this->parent::IO_ERRORS, true)) {
                    $errorInfo = $this->object->errorInfo();
                    $exception = new PDOException($errorInfo[2], $errorInfo[1]);
                    $exception->errorInfo = $errorInfo;
                    throw $exception;
                }
                if ($this->parent->getRound() === $this->parentRound) {
                    /* if not equal, parent has been reconnected */
                    $this->parent->reconnect();
                }
                $this->object = $this->parent->prepare($this->object->queryString);
                continue;
            }
            break;
        }
        /** @noinspection PhpUndefinedVariableInspection */
        return $ret;
    }
}
