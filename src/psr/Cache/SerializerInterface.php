<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Psr\Cache;

interface SerializerInterface
{
    public function serialize($value): string;

    public function unserialize(string $value);
}
