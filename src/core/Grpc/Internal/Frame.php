<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc\Internal;

use Swoole\Grpc\Compression\Compressor;
use Swoole\Grpc\Compression\IdentityCompressor;

/**
 * A gRPC length-prefixed message frame with support for the compression flag.
 *
 * Wire format: 1 byte compression flag + 4 bytes big-endian payload length + payload.
 * Parser::pack()/unpack() are intentionally left untouched (uncompressed, strict length check);
 * compressed frames go through this class instead.
 */
final class Frame
{
    public const HEADER_LENGTH = 5;

    /**
     * Encode one message into a frame, optionally compressing it.
     *
     * @param Compressor|null $compressor null means identity (uncompressed, flag 0).
     */
    public static function encode(string $payload, ?Compressor $compressor = null): string
    {
        $compressor = $compressor ?? new IdentityCompressor();
        $flag       = $compressor->flag();
        $body       = $flag === 0 ? $payload : $compressor->compress($payload);

        return pack('CN', $flag, strlen($body)) . $body;
    }

    /**
     * Parse a frame header. Returns [compressionFlag, payloadLength, totalLength].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function header(string $buffer): array
    {
        if (strlen($buffer) < self::HEADER_LENGTH) {
            throw new \InvalidArgumentException('Incomplete gRPC frame header');
        }
        $header = unpack('Ccompression/Nlength', substr($buffer, 0, self::HEADER_LENGTH));
        if ($header === false) {
            throw new \InvalidArgumentException('Invalid gRPC frame header');
        }

        return [$header['compression'], $header['length'], self::HEADER_LENGTH + $header['length']];
    }

    /**
     * Decode one complete frame, decompressing when the flag is set.
     *
     * @param array<int, Compressor> $compressors keyed by the compression flag
     */
    public static function decode(string $frame, array $compressors = []): string
    {
        [$flag, $length, $total] = self::header($frame);
        if (strlen($frame) < $total) {
            throw new \InvalidArgumentException('Incomplete gRPC frame payload');
        }
        $payload = substr($frame, self::HEADER_LENGTH, $length);

        if ($flag === 0) {
            return $payload;
        }
        $compressor = $compressors[$flag] ?? null;
        if ($compressor === null) {
            throw new \InvalidArgumentException("No decompressor for gRPC compression flag {$flag}");
        }

        return $compressor->decompress($payload);
    }
}
