<?php

namespace Swoole\Tests;

use Swoole\Thread\Runnable;

class TestThread extends Runnable
{
    public function run(array $args): void
    {
        $map = $args[1];
        $map->incr('thread', 1);

        for ($i = 0; $i < 5; $i++) {
            usleep(10000);
            $map->incr('sleep');
        }

        if ($map['sleep'] > 50) {
            $this->shutdown();
        }
    }
}
