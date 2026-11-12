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

use Swoole\Http2\Response;

/**
 * Extracts gRPC trailing metadata from an HTTP/2 response.
 *
 * Swoole >= (with the trailers enhancement) exposes a dedicated Response::$trailers property
 * carrying the trailing HEADERS frame. Stock/older Swoole only merges every header into
 * Response::$headers, so this helper falls back to reading the reserved "grpc-" keys there.
 * Swoole\Grpc is Composer-only and must keep working on any Swoole version, hence the
 * property_exists() feature detection.
 */
final class Trailers
{
    /** Prefix reserved by gRPC for status/trailing metadata on the wire. */
    private const GRPC_PREFIX = 'grpc-';

    /**
     * Return the response's trailing metadata as a lowercase-keyed map.
     *
     * @param Response|false|null $response
     * @return array<string, string>
     */
    public static function fromResponse($response): array
    {
        if (!$response instanceof Response) {
            return [];
        }

        // Prefer the dedicated trailers view when the extension provides one.
        if (property_exists($response, 'trailers')) {
            $trailers = $response->trailers;
            if (is_array($trailers) && $trailers !== []) {
                return self::normalize($trailers);
            }
        }

        // Fallback: stock Swoole merges trailers into headers. Filter the reserved grpc-* keys.
        $headers = $response->headers;
        if (!is_array($headers)) {
            return [];
        }

        $trailers = [];
        foreach ($headers as $key => $value) {
            if (is_string($key) && str_starts_with(strtolower($key), self::GRPC_PREFIX)) {
                $trailers[strtolower($key)] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }

        return $trailers;
    }

    /**
     * The numeric grpc-status code, or null when absent/unparseable.
     *
     * @param Response|false|null $response
     */
    public static function statusCode($response): ?int
    {
        $trailers = self::fromResponse($response);
        if (!isset($trailers['grpc-status'])) {
            return null;
        }

        return is_numeric($trailers['grpc-status']) ? (int) $trailers['grpc-status'] : null;
    }

    /**
     * The percent-decoded grpc-message, or an empty string when absent.
     *
     * @param Response|false|null $response
     */
    public static function statusMessage($response): string
    {
        $trailers = self::fromResponse($response);
        $message  = $trailers['grpc-message'] ?? '';

        return rawurldecode($message);
    }

    /**
     * @param array<mixed> $trailers
     * @return array<string, string>
     */
    private static function normalize(array $trailers): array
    {
        $normalized = [];
        foreach ($trailers as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalized[strtolower($key)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $normalized;
    }
}
