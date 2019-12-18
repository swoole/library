<?php
declare(strict_types=1);

namespace Swoole\Coroutine;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class Batch
{
    /**
     * 任务回调列表
     *
     * @var callable[]
     */
    private $taskCallables;

    public function __construct(array $taskCallables)
    {
        $this->taskCallables = $taskCallables;
    }

    /**
     * 执行并获取执行结果
     *
     * @return array
     */
    public function exec(): array
    {
        $channel = new Channel(1);
        $taskCount = count($this->taskCallables);
        $count = 0;
        $results = [];
        foreach ($this->taskCallables as $key => $callable) {
            $results[$key] = null;
            Coroutine::create(function () use ($key, $callable, $channel) {
                $channel->push([
                    'key'       =>  $key,
                    'result'    =>  $callable(),
                ]);
            });
        }
        while ($count < $taskCount) {
            $result = $channel->pop();
            if (false === $result) {
                break;
            }
            ++$count;
            $results[$result['key']] = $result['result'];
        }
        return $results;
    }
}
