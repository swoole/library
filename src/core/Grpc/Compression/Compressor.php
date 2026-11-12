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

/**
 * A gRPC message compressor. The compression flag is bit 0 of the 5-byte gRPC frame header;
 * the negotiated algorithm is advertised via the grpc-encoding / grpc-accept-encoding headers.
 */
interface Compressor
{
    /** The value used in the grpc-encoding header (e.g. "gzip", "identity"). */
    public function name(): string;

    /** The compression flag written into the frame header for an encoded message. */
    public function flag(): int;

    public function compress(string $data): string;

    public function decompress(string $data): string;
}
