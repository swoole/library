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

use Swoole\Grpc\Internal\Trailers;
use Swoole\Grpc\Status\RpcException;
use Swoole\Grpc\Status\Status;
use Swoole\Grpc\Status\StatusCode;
use Swoole\Http2\Response;

/**
 * A rich, idiosyncratic view of an RPC result, layered on the legacy
 * [reply, status, response] triple. Generated stubs and existing callers keep using the triple;
 * invokeUnary()/recvResult() return a Result for callers who want Status / trailers / exceptions.
 */
final class Result
{
    /** @var mixed */
    private $reply;

    private int $statusCode;

    /** @var Response|false|null */
    private $response;

    private Status $status;

    /**
     * @param mixed $reply
     * @param Response|false|null $response
     */
    public function __construct($reply, int $statusCode, $response)
    {
        $this->reply      = $reply;
        $this->statusCode = $statusCode;
        $this->response   = $response;
        $this->status     = self::buildStatus($reply, $statusCode, $response);
    }

    /**
     * Build a Result directly from the legacy triple returned by generated stubs.
     *
     * @param array{0: mixed, 1: int, 2: mixed} $triple
     */
    public static function fromTriple(array $triple): self
    {
        return new self($triple[0], $triple[1], $triple[2]);
    }

    /**
     * @return mixed
     */
    public function getReply()
    {
        return $this->reply;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return Response|false|null
     */
    public function getResponse()
    {
        return $this->response;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getTrailers(): array
    {
        return $this->status->getTrailers();
    }

    public function isOk(): bool
    {
        return $this->statusCode === StatusCode::OK;
    }

    /**
     * @return array{0: mixed, 1: int, 2: mixed}
     */
    public function toArray(): array
    {
        return [$this->reply, $this->statusCode, $this->response];
    }

    /**
     * @throws RpcException when the call failed
     */
    public function throwIfError(): self
    {
        $this->status->throwIfError();
        return $this;
    }

    public function toException(): RpcException
    {
        return $this->status->toException();
    }

    /**
     * @param mixed $reply
     * @param Response|false|null $response
     */
    private static function buildStatus($reply, int $statusCode, $response): Status
    {
        $trailers = Trailers::fromResponse($response);
        if ($statusCode === StatusCode::OK) {
            return new Status(StatusCode::OK, '', $trailers);
        }

        // Prefer the decoded grpc-message when available; otherwise the first triple element
        // is a human-readable error string produced by parseToResultArray().
        $message = $trailers['grpc-message'] ?? null;
        if ($message !== null) {
            $message = rawurldecode($message);
        } elseif (is_string($reply)) {
            $message = $reply;
        } else {
            $message = '';
        }

        // A negative code is a local/transport error (e.g. no response), not a gRPC status.
        $code = $statusCode < 0 ? StatusCode::UNKNOWN : $statusCode;

        return new Status($code, $message, $trailers);
    }
}
