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

class Barrier
{
    private $cid;

    public function __construct()
    {
        $this->cid = Co::getCid();
    }

    public function __destruct()
    {
        Coroutine::resume($this->cid);
    }

    public static function make()
    {
        return new static();
    }

    public static function wait(&$barrier)
    {
        $barrier = null;
        Coroutine::yield();
    }
}
