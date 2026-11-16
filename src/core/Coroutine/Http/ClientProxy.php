<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine\Http;

class ClientProxy
{
    private readonly array $headers;

    private readonly array $cookies;

    public function __construct(private readonly string $body, private readonly int $statusCode, ?array $headers, ?array $cookies)
    {
        $this->headers = $headers ?? [];
        $this->cookies = $cookies ?? [];
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Parse the final header block, discarding interim responses and redirects.
     *
     * @param list<string> $lines
     */
    public static function fromHeaders(string $body, array $lines, int $defaultStatus = 200): self
    {
        $status  = $defaultStatus;
        $headers = $cookies = [];
        foreach ($lines as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches)) {
                $status  = (int) $matches[1];
                $headers = $cookies = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name             = strtolower(trim($parts[0]));
            $value            = trim($parts[1]);
            $headers[$name][] = $value;
            if ($name === 'set-cookie') {
                $cookie = explode('=', explode(';', $value, 2)[0], 2);
                if (count($cookie) === 2 && trim($cookie[0]) !== '') {
                    $cookies[trim($cookie[0])] = trim($cookie[1]);
                }
            }
        }
        return new self($body, $status, $headers, $cookies);
    }
}
