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

use Swoole\Grpc\Internal\FrameReassembler;
use Swoole\Grpc\Internal\Trailers;
use Swoole\Grpc\Status\Status;
use Swoole\Grpc\Status\StatusCode;

/**
 * An active call whose messages are exchanged over one HTTP/2 stream.
 *
 * The original send()/push()/recv()/end() contract is unchanged: recv() still returns the
 * [reply, status, response] triple for one HTTP/2 response. On top of it, this class offers
 * status/trailers introspection, a cross-DATA-frame message iterator and local cancellation.
 */
class StreamingCall extends BaseCall implements \IteratorAggregate
{
    private Status $status;

    /** @var array<string, string> */
    private array $trailers = [];

    private bool $ended = false;

    private bool $cancelled = false;

    private ?FrameReassembler $reassembler = null;

    public function __construct()
    {
        $this->status = new Status();
    }

    /**
     * Open the stream with a single (optional) first message.
     *
     * @param object|null $message
     */
    public function send($message = null): bool
    {
        if ($this->streamId !== 0) {
            return false;
        }

        $this->streamId = $this->client->openStream(
            $this->method,
            $message === null ? '' : Parser::serializeMessage($message),
            'POST',
            true,
            $this->headers
        );

        return $this->streamId > 0;
    }

    /**
     * Write a message on the stream, opening it first if needed.
     *
     * @param object $message
     */
    public function push($message): bool
    {
        if ($this->streamId === 0) {
            // gRPC always uses POST; an empty first frame still has to be a POST request.
            $this->streamId = $this->client->openStream($this->method, '', 'POST', true, $this->headers);
        }

        return $this->client->write($this->streamId, Parser::serializeMessage($message), false);
    }

    /**
     * @return array{0: mixed, 1: int, 2: mixed}
     */
    public function recv(float $timeout = Client::DEFAULT_TIMEOUT): array
    {
        if ($this->cancelled || $this->streamId <= 0) {
            $response = false;
        } else {
            $response = $this->client->recv($this->streamId, $timeout);
            if (!$this->client->isStreamExist($this->streamId)) {
                // The final response (or a timeout) unregistered the stream.
                $this->streamId = 0;
                $this->ended    = true;
            }
        }

        $this->observe($response);

        return Parser::parseToResultArray($response, $this->deserialize);
    }

    /**
     * Idiomatic single-read entry point returning a Result instead of the legacy triple.
     */
    public function recvResult(float $timeout = Client::DEFAULT_TIMEOUT): Result
    {
        return Result::fromTriple($this->recv($timeout));
    }

    /**
     * Half-close the write side. The stream id is kept so recv() can still read the final reply.
     */
    public function end(): bool
    {
        if ($this->streamId === 0) {
            return false;
        }

        return $this->client->write($this->streamId, null, true);
    }

    /**
     * Iterate complete messages, reassembling them across HTTP/2 DATA fragments. Each yielded
     * value is a deserialized reply (or null on end-of-stream status). This is the idiomatic
     * streaming API; callers who need per-response control can keep using recv() directly.
     *
     * @return \Traversable<int, mixed>
     */
    public function getIterator(): \Traversable
    {
        $reassembler = $this->reassembler ?? ($this->reassembler = new FrameReassembler());

        while (!$this->ended && !$this->cancelled) {
            if ($this->streamId <= 0) {
                $this->observe(false);
                $this->ended = true;
                break;
            }

            $response = $this->client->recv($this->streamId, Client::DEFAULT_TIMEOUT);
            if (!$this->client->isStreamExist($this->streamId)) {
                $this->streamId = 0;
            }

            $pushed = $reassembler->push($response);
            foreach ($pushed['frames'] as $frame) {
                yield Parser::deserializeMessage($this->deserialize, $frame);
            }

            if ($pushed['done']) {
                $this->observe($pushed['response']);
                $this->ended = true;
            }
        }
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
        return $this->trailers;
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    /**
     * Locally cancel the call: stop reading and mark it ended with CANCELLED.
     *
     * The underlying Swoole Coroutine\Http2\Client has no per-stream RST_STREAM API (goaway is
     * connection-wide), so this discards pending reads locally rather than signalling the peer.
     */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }
        $this->cancelled = true;
        $this->ended     = true;
        $this->status    = new Status(StatusCode::CANCELLED, 'cancelled locally');
        $this->streamId  = 0;
    }

    /**
     * @param \Swoole\Http2\Response|false|null $response
     */
    private function observe($response): void
    {
        $this->trailers = Trailers::fromResponse($response);
        if (!$this->cancelled) {
            $status = Status::fromResponse($response);
            // A missing response mid-stream is a transport-level failure, not a gRPC status.
            $this->status = !$response && !$this->ended
                ? new Status(StatusCode::UNAVAILABLE, 'No response')
                : $status;
        }
    }
}
