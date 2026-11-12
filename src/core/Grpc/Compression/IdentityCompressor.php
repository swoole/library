<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc\Compression;

/** The default, no-op compressor (grpc-encoding: identity). */
final class IdentityCompressor implements Compressor
{
    public function name(): string
    {
        return 'identity';
    }

    public function flag(): int
    {
        return 0;
    }

    public function compress(string $data): string
    {
        return $data;
    }

    public function decompress(string $data): string
    {
        return $data;
    }
}
