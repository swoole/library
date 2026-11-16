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

class Manager
{
    protected ?Pool $pool = null;

    /**
     * @var int
     */
    protected $ipcType = SWOOLE_IPC_NONE;

    /**
     * @var int
     */
    protected $msgQueueKey = 0;

    /**
     * @var array
     */
    protected $startFuncMap = [];

    private bool $running = false;

    public function __construct(int $ipcType = SWOOLE_IPC_NONE, int $msgQueueKey = 0)
    {
        $this->setIPCType($ipcType)->setMsgQueueKey($msgQueueKey);
    }

    public function add(callable $func, bool $enableCoroutine = false): self
    {
        $this->addBatch(1, $func, $enableCoroutine);
        return $this;
    }

    public function addBatch(int $workerNum, callable $func, bool $enableCoroutine = false): self
    {
        if ($workerNum < 1) {
            throw new \InvalidArgumentException('workerNum must be greater than 0');
        }
        $this->assertStopped();
        for ($i = 0; $i < $workerNum; $i++) {
            $this->startFuncMap[] = [$func, $enableCoroutine];
        }
        return $this;
    }

    public function start(): void
    {
        $this->assertStopped();
        if ($this->startFuncMap === []) {
            throw new \LogicException('Process manager requires at least one worker');
        }
        $this->pool = new Pool(count($this->startFuncMap), $this->ipcType, $this->msgQueueKey, false);

        $this->pool->on(Constant::EVENT_WORKER_START, function (Pool $pool, int $workerId) {
            [$func, $enableCoroutine] = $this->startFuncMap[$workerId];
            if ($enableCoroutine) {
                run($func, $pool, $workerId);
            } else {
                $func($pool, $workerId);
            }
        });

        $this->running = true;
        try {
            $this->pool->start();
        } finally {
            $this->running = false;
            $this->pool    = null;
        }
    }

    public function shutdown(): bool
    {
        return $this->running && $this->pool->shutdown();
    }

    public function setIPCType(int $ipcType): self
    {
        $this->assertStopped();
        $this->ipcType = $ipcType;
        return $this;
    }

    public function getIPCType(): int
    {
        return $this->ipcType;
    }

    public function setMsgQueueKey(int $msgQueueKey): self
    {
        $this->assertStopped();
        $this->msgQueueKey = $msgQueueKey;
        return $this;
    }

    public function getMsgQueueKey(): int
    {
        return $this->msgQueueKey;
    }

    private function assertStopped(): void
    {
        if ($this->running) {
            throw new \LogicException('Process manager is already running');
        }
    }
}
