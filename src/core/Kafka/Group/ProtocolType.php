<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Group;

class ProtocolType
{
    public const CONSUMER = 'consumer';

    public const CONNECT = 'connect';

    private function __construct()
    {
    }
}
