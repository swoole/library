<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc;

/**
 * gRPC metadata helpers.
 *
 * Metadata keys are lowercase ASCII; keys ending in "-bin" carry binary values that MUST be
 * base64-encoded on the wire (RFC 4648, unpadded). Multiple values for one key are joined with
 * ",", matching the HTTP/2 header semantics of the underlying Swoole client.
 */
final class Metadata
{
    private const BIN_SUFFIX = '-bin';

    private function __construct()
    {
    }

    /**
     * Normalize a metadata map into HTTP/2 header values.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, string>
     */
    public static function normalize(array $metadata): array
    {
        $headers = [];
        foreach ($metadata as $key => $value) {
            $key = strtolower((string) $key);
            if (!self::isValidKey($key)) {
                continue;
            }
            $headers[$key] = self::encodeValue($key, $value);
        }

        return $headers;
    }

    /**
     * Decode a "-bin" header value (possibly a comma-joined list) into raw bytes.
     *
     * @return array<int, string>
     */
    public static function decodeBinary(string $key, string $value): array
    {
        if (!str_ends_with(strtolower($key), self::BIN_SUFFIX)) {
            return [$value];
        }

        $decoded = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            // Restore the padding removed on encode before decoding.
            $remainder = strlen($part) % 4;
            if ($remainder !== 0) {
                $part .= str_repeat('=', 4 - $remainder);
            }
            $raw = base64_decode($part, true);
            if ($raw !== false) {
                $decoded[] = $raw;
            }
        }

        return $decoded;
    }

    private static function isValidKey(string $key): bool
    {
        // ASCII letters, digits and - _ . only; must not start with the reserved "grpc-" prefix.
        return $key !== ''
            && !str_starts_with($key, 'grpc-')
            && preg_match('/^[a-z0-9_.-]+$/', $key) === 1;
    }

    /**
     * @param mixed $value
     */
    private static function encodeValue(string $key, $value): string
    {
        $values = is_array($value) ? $value : [$value];
        $parts  = [];
        foreach ($values as $item) {
            if (str_ends_with($key, self::BIN_SUFFIX)) {
                // Binary values use standard base64 with the trailing "=" padding removed.
                $parts[] = rtrim(base64_encode((string) $item), '=');
            } else {
                // ASCII values must be printable (0x20..0x7E); strip illegal bytes.
                $parts[] = preg_replace('/[^\x20-\x7E]/', '', (string) $item) ?? '';
            }
        }

        return implode(',', $parts);
    }
}
