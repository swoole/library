<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine;

use Swoole\Coroutine;
use Swoole\Exception;

class Barrier
{
    private $cid = -1;

    public function __destruct()
    {
        Coroutine::resume($this->cid);
    }

    public static function make()
    {
        return new static();
    }

    /**
     * @throws Exception
     */
    public static function wait(Barrier &$barrier)
    {
        if ($barrier->cid != -1) {
            throw new Exception('The barrier is waiting, cannot wait again.');
        }
        $barrier->cid = Coroutine::getCid();
        $barrier = null;
        Coroutine::yield();
    }
}
