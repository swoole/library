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

use Swoole\FastCGI\Record\EndRequest;
use Swoole\FastCGI\Record\Stderr;
use Swoole\FastCGI\Record\Stdout;
use Swoole\Http\Status;

class HttpResponse extends Response
{
    /** @var int */
    protected $statusCode;

    /** @var string */
    protected $reasonPhrase;

    /**
     * @var array<string, string|list<string>>
     */
    protected array $headers = [];

    /**
     * @var array<string, string>
     */
    protected array $headersMap = [];

    /**
     * @var array<string>
     */
    protected array $setCookieHeaderLines = [];

    private bool $headersComplete = false;

    private bool $invalidResponse = false;

    /**
     * @param iterable<Stdout|Stderr|EndRequest> $records
     */
    public function __construct(iterable $records = [])
    {
        parent::__construct($records);
        if (!$this->headersComplete) {
            if ($this->body !== '') {
                $this->withError($this->body);
            }
            $this->invalidateResponse();
        }
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
        return $name ? implode(', ', (array) $this->headers[$name]) : null;
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string $value): self
    {
        $previous = $this->headersMap[strtolower($name)] ?? null;
        if ($previous !== null && $previous !== $name) {
            unset($this->headers[$previous]);
        }
        $this->headers[$name]                = $value;
        $this->headersMap[strtolower($name)] = $name;
        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->withHeader($name, $value);
        }
        return $this;
    }

    /**
     * @return array<string>
     */
    public function getSetCookieHeaderLines(): array
    {
        return $this->setCookieHeaderLines;
    }

    public function withSetCookieHeaderLine(string $value): self
    {
        $this->setCookieHeaderLines[] = $value;
        return $this;
    }

    protected function appendStdout(string $content): void
    {
        if ($this->invalidResponse) {
            return;
        }
        $offset = max(0, strlen($this->body) - 3);
        parent::appendStdout($content);
        if ($this->headersComplete || !preg_match('/\r?\n\r?\n/', $this->body, $separator, PREG_OFFSET_CAPTURE, $offset)) {
            return;
        }
        [$newline, $position]  = $separator[0];
        $headers               = substr($this->body, 0, $position);
        $this->body            = substr($this->body, $position + strlen($newline));
        $this->headersComplete = true;
        foreach (explode("\n", $headers) as $header) {
            $array = explode(':', $header, 2); // An array that contains the name and the value of an HTTP header.
            if (count($array) != 2) {
                continue; // Invalid HTTP header? Ignore it!
            }
            $name  = trim($array[0]);
            $value = trim($array[1]);
            if (strcasecmp($name, 'Status') === 0) {
                if (!preg_match('/^([1-9][0-9]{2})(?: (.*))?$/D', $value, $status)) {
                    $this->withError($headers);
                    $this->invalidateResponse();
                    return;
                }
                $statusCode   = (int) $status[1];
                $reasonPhrase = $status[2] ?? null;
            } elseif (strcasecmp($name, 'Set-Cookie') === 0) {
                $this->withSetCookieHeaderLine($value);
            } else {
                $key = $this->headersMap[strtolower($name)] ?? null;
                if ($key === null) {
                    $this->withHeader($name, $value);
                } else {
                    $this->headers[$key] = [...(array) $this->headers[$key], $value];
                }
            }
        }
        $statusCode   = $statusCode ?? ($this->getHeader('Location') ? Status::FOUND : Status::OK);
        $reasonPhrase = $reasonPhrase ?? Status::getReasonPhrase($statusCode);
        $this->withStatusCode($statusCode)->withReasonPhrase($reasonPhrase);
    }

    private function invalidateResponse(): void
    {
        $this->invalidResponse = true;
        $this->body            = '';
        $this->headers         = $this->headersMap = $this->setCookieHeaderLines = [];
        $this->withStatusCode(Status::BAD_GATEWAY)->withReasonPhrase('Invalid FastCGI Response');
    }
}
