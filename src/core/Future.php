<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole;

use Swoole\Coroutine\Channel;
use Throwable;

class Future
{
    /**
     * @var callable
     */
    private $func;

    public function __construct(callable $func)
    {
        $this->func = $func;
    }

    public static function create(callable $func): self
    {
        return new self($func);
    }

    public static function join(array $futures, float $timeout = -1): array
    {
        $len = count($futures);
        $ch = new Channel($len);
        $rets = [];

        Coroutine::join(array_map(static function (Future $future) use ($ch): int {
            return $future->run($ch);
        }, $futures));

        while ($len--) {
            $ret = $ch->pop($timeout);

            if ($ret instanceof Throwable) {
                throw $ret;
            }

            $rets[] = $ret;
        }

        return $rets;
    }

    public function run(Channel $channel): int
    {
        return Coroutine::create(function () use ($channel) {
            try {
                $channel->push(($this->func)());
            } catch (Throwable $throwable) {
                $channel->push($throwable);
            }
        });
    }

    public function await(float $timeout = -1)
    {
        $ch = new Channel(1);

        $cid = $this->run($ch);

        $ret = $ch->pop($timeout);

        if ($ret instanceof Throwable) {
            throw $ret;
        }

        return $ret;
    }
}
