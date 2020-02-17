<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\FastCGI;

use Swoole\Http\Status;

class HttpResponse extends Response
{
    /** @var int */
    protected $statusCode;

    /** @var string */
    protected $reasonPhrase;

    /** @var array */
    protected $headers = [];

    /** @var array */
    protected $headersMap = [];

    public function __construct(array $records = [])
    {
        parent::__construct($records);
        $body = (string) $this->getBody();
        if (strlen($body) === 0) {
            return;
        }
        [$headers, $body] = @explode("\r\n\r\n", $body, 2);
        $headers = explode("\r\n", $headers);
        if ($headers['Status'] ?? null) {
            [$statusCode, $reasonPhrase] = @explode(' ', $headers['Status'], 2);
            unset($headers['Status']);
        }
        $statusCode = (int) ($statusCode ?? Status::INTERNAL_SERVER_ERROR);
        $reasonPhrase = (string) ($reasonPhrase ?? Status::getReasonPhrase($statusCode));
        $this->withStatusCode($statusCode)->withReasonPhrase($reasonPhrase);
        foreach ($headers as $header) {
            [$name, $value] = @explode(': ', $header, 2);
            $this->withHeader($name, $value);
        }
        $this->withBody($body);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function withReasonPhrase(string $reasonPhrase): self
    {
        $this->reasonPhrase = $reasonPhrase;
        return $this;
    }

    public function getHeader(string $name): ?string
    {
        $name = $this->headersMap[strtolower($name)] ?? null;
        return $name ? $this->headers[$name] : null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        $this->headersMap[strtolower($name)] = $name;
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->withHeader($name, $value);
        }
        return $this;
    }
}
