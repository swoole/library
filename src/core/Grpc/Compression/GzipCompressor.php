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

/** gzip message compression (grpc-encoding: gzip). Requires ext-zlib. */
final class GzipCompressor implements Compressor
{
    public function __construct()
    {
        if (!function_exists('gzencode')) {
            throw new \RuntimeException('gzip compression requires ext-zlib');
        }
    }

    public function name(): string
    {
        return 'gzip';
    }

    public function flag(): int
    {
        return 1;
    }

    public function compress(string $data): string
    {
        $encoded = gzencode($data);
        if ($encoded === false) {
            throw new \RuntimeException('gzip compression failed');
        }

        return $encoded;
    }

    public function decompress(string $data): string
    {
        $decoded = @gzdecode($data);
        if ($decoded === false) {
            throw new \RuntimeException('gzip decompression failed');
        }

        return $decoded;
    }
}
