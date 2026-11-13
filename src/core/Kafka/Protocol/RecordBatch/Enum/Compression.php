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

class Compression
{
    public const NONE = 0;

    public const GZIP = 1;

    public const SNAPPY = 2;

    public const LZ4 = 3;

    public const ZSTD = 4;

    private function __construct()
    {
    }
}
