<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Process;

use Swoole\Constant;
use function Swoole\Coroutine\run;

class ProcessManager
{
    protected $pool;

    protected $workerNum = 0;

    protected $ipcType = 0;

    protected $msgQueueKey = 0;

    protected $startFuncMap = [];

    public function __construct(int $ipcType = 0, int $msgQueueKey = 0)
    {
        $this->ipcType = $ipcType;
        $this->msgQueueKey = $msgQueueKey;
    }

    public function add(callable $func, bool $enableCoroutine = false): void
    {
        $this->addBatch(1, $func, $enableCoroutine);
    }

    public function setIPCType(int $ipcType): ProcessManager
    {
        $this->ipcType = $ipcType;
        return $this;
    }

    public function getIPCType(): int
    {
        return $this->ipcType;
    }

    public function setMsgQueueKey(int $msgQueueKey): ProcessManager
    {
        $this->msgQueueKey = $msgQueueKey;
        return $this;
    }

    public function getMsgQueueKey(): int
    {
        return $this->msgQueueKey;
    }

    public function addBatch(int $workerNum, callable $func, bool $enableCoroutine = false): ProcessManager
    {
        for ($i = 0; $i < $workerNum; $i++) {
            $this->startFuncMap[] = [$func, $enableCoroutine];
            $this->workerNum += 1;
        }
        return $this;
    }

    public function start(): void
    {
        $this->pool = new Pool($this->workerNum, $this->ipcType, $this->msgQueueKey, false);

        $this->pool->on(Constant::EVENT_WORKER_START, function (Pool $pool, int $workerId) {
            [$func, $enableCoroutine] = $this->startFuncMap[$workerId];
            if ($enableCoroutine) {
                run($func, $pool, $workerId);
            } else {
                $func($pool, $workerId);
            }
        });

        $this->pool->start();
    }
}
