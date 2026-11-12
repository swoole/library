<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc\Status;

use Swoole\Grpc\Internal\Trailers;
use Swoole\Http2\Response;

/**
 * The outcome of an RPC: a gRPC status code, an optional message and trailing metadata.
 *
 * This is a value object layered on top of the existing [reply, status, response] triple: it is
 * built from the third element (the raw HTTP/2 response) and never changes that triple.
 */
final class Status
{
    private int $code;

    private string $message;

    /** @var array<string, string> */
    private array $trailers;

    /**
     * @param array<string, string> $trailers
     */
    public function __construct(int $code = StatusCode::OK, string $message = '', array $trailers = [])
    {
        $this->code     = $code;
        $this->message  = $message;
        $this->trailers = $trailers;
    }

    /**
     * Build a Status from a raw HTTP/2 response. A missing/false response maps to UNKNOWN with
     * a "no response" message; HTTP-level and grpc-status errors are surfaced through the code.
     *
     * @param Response|false|null $response
     */
    public static function fromResponse($response): self
    {
        if (!$response instanceof Response) {
            return new self(StatusCode::UNKNOWN, 'No response');
        }

        // Non-2xx HTTP status: no usable gRPC trailers, map to UNKNOWN with the HTTP status.
        if ($response->statusCode !== 0 && intdiv($response->statusCode, 100) !== 2) {
            return new self(StatusCode::UNKNOWN, 'Http status Error', ['http-status' => (string) $response->statusCode]);
        }

        $code = Trailers::statusCode($response);
        if ($code === null) {
            // A successful response with no grpc-status (e.g. an intermediate streaming DATA
            // frame) is treated as OK.
            $code = StatusCode::OK;
        }

        return new self($code, Trailers::statusMessage($response), Trailers::fromResponse($response));
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getName(): string
    {
        return StatusCode::name($this->code);
    }

    /**
     * @return array<string, string>
     */
    public function getTrailers(): array
    {
        return $this->trailers;
    }

    public function ok(): bool
    {
        return $this->code === StatusCode::OK;
    }

    public function isRetryable(): bool
    {
        return StatusCode::isRetryable($this->code);
    }

    public function toException(): RpcException
    {
        return new RpcException($this);
    }

    /**
     * Throw when the call did not succeed. Returns $this for fluent use on success.
     *
     * @throws RpcException
     */
    public function throwIfError(): self
    {
        if (!$this->ok()) {
            throw $this->toException();
        }

        return $this;
    }
}
