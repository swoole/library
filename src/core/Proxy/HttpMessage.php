<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Proxy;

/**
 * Header and request-target rules for the reverse proxy.
 *
 * Hop-by-hop headers are dropped, including every name listed in Connection. Header values that
 * contain a line break are dropped so a client cannot inject a header.
 */
final class HttpMessage
{
    public const ALLOWED_METHODS = 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS';

    /** @var array<string, true> */
    private const HOP_BY_HOP = [
        'connection'          => true,
        'keep-alive'          => true,
        'proxy-authenticate'  => true,
        'proxy-authorization' => true,
        'proxy-connection'    => true,
        'te'                  => true,
        'trailer'             => true,
        'transfer-encoding'   => true,
        'upgrade'             => true,
        'content-length'      => true,
        'expect'              => true,
    ];

    /** @var array<string, true> */
    private const METHODS = [
        'GET'     => true,
        'HEAD'    => true,
        'POST'    => true,
        'PUT'     => true,
        'PATCH'   => true,
        'DELETE'  => true,
        'OPTIONS' => true,
    ];

    public static function isMethodAllowed(string $method): bool
    {
        return isset(self::METHODS[strtoupper($method)]);
    }

    /**
     * Rebuild the origin-form target. Swoole keeps the path in request_uri and the query in
     * query_string, so a query of "0" is still present and must be put back.
     *
     * @param array<mixed> $server
     */
    public static function requestTargetFromServer(array $server): ?string
    {
        $uri = $server['request_uri'] ?? '';
        if (!is_string($uri)) {
            return null;
        }
        $query = $server['query_string'] ?? null;
        if (is_string($query) && $query !== '' && !str_contains($uri, '?')) {
            $uri .= '?' . $query;
        }

        return self::requestTarget($uri);
    }

    /**
     * Origin-form request target. Anything else, including an absolute URL, is rejected.
     */
    public static function requestTarget(string $uri): ?string
    {
        if ($uri === '' || $uri[0] !== '/') {
            return null;
        }
        if (str_contains($uri, '://') || str_contains($uri, '#') || preg_match('/[\x00-\x1f\x7f]/', $uri) === 1) {
            return null;
        }

        return $uri;
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, string>
     */
    public static function requestHeaders(
        array $headers,
        string $backendHost,
        int $backendPort,
        bool $ssl,
        bool $preserveHost,
        ?string $remoteAddress = null,
    ): array {
        $drop     = self::hopByHop($headers);
        $outgoing = [];
        foreach ($headers as $name => $value) {
            $header = strtolower((string) $name);
            if (isset($drop[$header]) || !self::isHeaderName($header)) {
                continue;
            }
            $rendered = self::renderValue($value);
            if ($rendered === null) {
                continue;
            }
            $outgoing[$header] = $rendered;
        }
        if (!$preserveHost || !isset($outgoing['host'])) {
            $outgoing['host'] = self::hostHeader($backendHost, $backendPort, $ssl);
        }
        if ($remoteAddress !== null && preg_match('/^[0-9A-Za-z:.]+$/', $remoteAddress) === 1) {
            $existing                      = $outgoing['x-forwarded-for'] ?? '';
            $outgoing['x-forwarded-for']   = $existing === '' ? $remoteAddress : $existing . ', ' . $remoteAddress;
        }

        return $outgoing;
    }

    /**
     * @param array<mixed> $headers
     * @param list<mixed> $setCookies raw Set-Cookie lines, which must not be joined with a comma
     * @return array{headers: array<string, string|list<string>>, cookies: list<string>}
     */
    public static function responseHeaders(array $headers, array $setCookies): array
    {
        $drop     = self::hopByHop($headers);
        $outgoing = [];
        foreach ($headers as $name => $value) {
            $header = strtolower((string) $name);
            if ($header === 'set-cookie' || isset($drop[$header]) || !self::isHeaderName($header)) {
                continue;
            }
            $rendered = self::renderResponseValue($value);
            if ($rendered === null) {
                continue;
            }
            $outgoing[$header] = $rendered;
        }
        $cookies = [];
        foreach ($setCookies as $cookie) {
            if (!is_string($cookie) || $cookie === '' || preg_match('/[\r\n\x00]/', $cookie) === 1) {
                continue;
            }
            $cookies[] = $cookie;
        }
        if ($cookies === [] && isset($headers['set-cookie'])) {
            $fallback = self::renderResponseValue($headers['set-cookie']);
            if (is_string($fallback) && $fallback !== '') {
                $cookies[] = $fallback;
            } elseif (is_array($fallback)) {
                $cookies = $fallback;
            }
        }

        return ['headers' => $outgoing, 'cookies' => $cookies];
    }

    public static function hostHeader(string $host, int $port, bool $ssl): string
    {
        $formatted = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $default   = $ssl ? 443 : 80;
        if ($port === $default) {
            return $formatted;
        }

        return $formatted . ':' . $port;
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, true>
     */
    private static function hopByHop(array $headers): array
    {
        $names      = self::HOP_BY_HOP;
        $connection = $headers['connection'] ?? $headers['Connection'] ?? '';
        if (is_array($connection)) {
            $connection = implode(',', $connection);
        }
        foreach (explode(',', (string) $connection) as $token) {
            $token = strtolower(trim($token));
            if ($token !== '' && self::isHeaderName($token)) {
                $names[$token] = true;
            }
        }

        return $names;
    }

    private static function isHeaderName(string $name): bool
    {
        return $name !== '' && strlen($name) <= 127 && preg_match('/^[!#$%&\'*+.^_`|~0-9a-z-]+$/', $name) === 1;
    }

    private static function renderValue(mixed $value): ?string
    {
        $parts = self::parts($value);
        if ($parts === null || $parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }

    /**
     * @return string|list<string>|null
     */
    private static function renderResponseValue(mixed $value): string|array|null
    {
        $parts = self::parts($value);
        if ($parts === null || $parts === []) {
            return null;
        }
        if (count($parts) === 1) {
            return $parts[0];
        }

        return $parts;
    }

    /**
     * @return list<string>|null
     */
    private static function parts(mixed $value): ?array
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (!is_string($item)) {
                    return null;
                }
                $item = trim($item);
                if ($item === '' || preg_match('/[\r\n\x00]/', $item) === 1) {
                    return null;
                }
                $parts[] = $item;
            }

            return $parts;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || preg_match('/[\r\n\x00]/', $value) === 1) {
            return null;
        }

        return [$value];
    }
}
