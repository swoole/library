<?php
declare(strict_types=1);

namespace Swoole\Connection\Db;

use PDO;
use PDOException;
use Swoole\ObjectProxy;

class PDOProxy extends ObjectProxy
{
    public const IO_ERRORS = [
        2002, // MYSQLND_CR_CONNECTION_ERROR
        2006, // MYSQLND_CR_SERVER_GONE_ERROR
        2013, // MYSQLND_CR_SERVER_LOST
    ];

    /** @var callable */
    protected $constructor;
    /** @var PDO */
    protected $object;
    /** @var int */
    protected $round = 0;

    public function __construct(callable $constructor)
    {
        parent::__construct($constructor());
        $this->object->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->constructor = $constructor;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function reconnect(): void
    {
        $constructor = $this->constructor;
        parent::__construct($constructor());
        $this->round++;
    }

    /** @noinspection PhpUnused */
    public function __call(string $name, array $arguments)
    {
        for ($n = 3; $n--;) {
            $ret = @$this->object->$name(...$arguments);
            if ($ret === false) {
                /* no more chances or non-IO failures */
                if ($n === 0 || !in_array($this->object->errorInfo()[1], static::IO_ERRORS, true)) {
                    $errorInfo = $this->object->errorInfo();
                    $exception = new PDOException($errorInfo[2], $errorInfo[1]);
                    $exception->errorInfo = $errorInfo;
                    throw $exception;
                }
                $this->reconnect();
                continue;
            }
            if (
                strcasecmp($name, 'prepare') === 0 ||
                strcasecmp($name, 'query') === 0
            ) {
                $ret = new PDOStatementProxy($ret, $this);
            }
            break;
        }
        /** @noinspection PhpUndefinedVariableInspection */
        return $ret;
    }

}
