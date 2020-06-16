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

    protected $ipcType;

    protected $msgqueueKey;

    protected $startFuncMap = [];

    public function __construct(int $ipcType = 0, int $msgqueueKey = 0)
    {
        $this->ipcType = $ipcType;
        $this->msgqueueKey = $msgqueueKey;
    }

    public function add(callable $func, bool $enableCoroutine = false)
    {
        $this->addBatch(1, $func, $enableCoroutine);
    }

    public function addBatch(int $workerNum, callable $func, bool $enableCoroutine = false)
    {
        for ($i = 0; $i < $workerNum; $i++) {
            $this->startFuncMap[] = [$func, $enableCoroutine];
            $this->workerNum += 1;
        }
    }

    public function start()
    {
        $this->pool = new Pool($this->workerNum, $this->ipcType, $this->msgqueueKey, false);

        $this->pool->on(Constant::EVENT_WORKER_START, function (Pool $pool, int $workerId) {
            [$func, $enableCoroutine] = $this->startFuncMap[$workerId];
            if ($enableCoroutine) {
                run($func, $pool, $workerId);
            } else {
                call_user_func($func, $pool, $workerId);
            }
        });

        $this->pool->start();
    }
}
