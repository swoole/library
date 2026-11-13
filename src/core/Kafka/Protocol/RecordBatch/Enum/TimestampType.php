<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\RecordBatch\Enum;

class TimestampType
{
    public const CREATE_TIME = 0;

    public const LOG_APPEND_TIME = 1;

    private function __construct()
    {
    }
}
