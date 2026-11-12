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
use Swoole\Grpc\Internal\ResponseCopy;
use Swoole\Grpc\Internal\Trailers;
use Swoole\Grpc\Status\Status;
use Swoole\Grpc\Status\StatusCode;

/**
 * An active call whose messages are exchanged over one HTTP/2 stream.
 *
 * send(), push() and end() keep their original contract. recv() returns one reassembled gRPC
 * message as the [reply, status, response] triple. The class also offers status and trailer
 * introspection, a message iterator and local cancellation.
 */
class StreamingCall extends BaseCall implements \IteratorAggregate
{
    private Status $status;

    /** @var array<string, string> */
    private array $trailers = [];

    private bool $ended = false;

    private bool $cancelled = false;

    private ?FrameReassembler $reassembler = null;

    /**
     * Complete messages already decoded from a chunk that held more than one, or a partial
     * message still waiting for the rest of its bytes lives in the reassembler.
     *
     * @var list<array{frame: string, response: \Swoole\Http2\Response, done: bool}>
     */
    private array $pendingFrames = [];

    /** Per-call deadline in seconds. Null uses the underlying client's timeout. */
    private ?float $timeout = null;

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
            if ($this->streamId <= 0) {
                $this->streamId = 0;
                return false;
            }
        }

        return $this->client->write($this->streamId, Parser::serializeMessage($message), false);
    }

    public function setTimeout(?float $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * Read one gRPC message. A message split across HTTP/2 DATA frames is reassembled first.
     * Several messages packed into one chunk are returned one per call.
     *
     * Omit $timeout to use the per-call timeout when one was set, and otherwise the client timeout.
     * An explicit value, including -1, is used as given.
     *
     * @return array{0: mixed, 1: int, 2: mixed}
     */
    public function recv(?float $timeout = null): array
    {
        if ($this->cancelled || ($this->streamId <= 0 && $this->pendingFrames === [])) {
            $this->observe(false, Client::RECV_MISSING);
            return Parser::parseToResultArray(false, $this->deserialize);
        }

        $effective   = $timeout ?? $this->timeout;
        $reassembler = $this->reassembler ??= new FrameReassembler();

        while ($this->pendingFrames === []) {
            if ($this->streamId <= 0) {
                $this->ended = true;
                $this->observe(false, Client::RECV_MISSING);
                return Parser::parseToResultArray(false, $this->deserialize);
            }

            $streamId = $this->streamId;
            $response = $this->client->recv($streamId, $effective);
            $failure  = $this->client->consumeRecvFailure($streamId);
            if (!$this->client->isStreamExist($streamId)) {
                $this->streamId = 0;
            }
            if ($failure === Client::RECV_TIMEOUT) {
                $this->ended = true;
                $this->observe(false, $failure);
                return ['Deadline exceeded', StatusCode::DEADLINE_EXCEEDED, $response];
            }
            if (!$response instanceof \Swoole\Http2\Response) {
                $this->ended = true;
                $this->observe(false, $failure);
                return Parser::parseToResultArray(false, $this->deserialize);
            }

            $pushed = $reassembler->push($response);
            $last   = count($pushed['frames']) - 1;
            foreach ($pushed['frames'] as $index => $frame) {
                $this->pendingFrames[] = [
                    'frame'    => $frame,
                    'response' => $response,
                    'done'     => $pushed['done'] && $index === $last,
                ];
            }
            if ($this->pendingFrames !== []) {
                break;
            }
            if ($pushed['done']) {
                $this->streamId = 0;
                $this->ended    = true;
                $this->observe($response, Client::RECV_OK);
                return Parser::parseToResultArray($response, $this->deserialize);
            }
        }

        return $this->emitPending();
    }

    /**
     * Idiomatic single-read entry point returning a Result instead of the legacy triple.
     */
    public function recvResult(?float $timeout = null): Result
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
     * value is a deserialized reply. The iteration stops when the stream ends. Callers who
     * need per-response control can keep using recv() directly.
     *
     * @return \Traversable<int, mixed>
     */
    public function getIterator(): \Traversable
    {
        while (!$this->ended && !$this->cancelled) {
            [$reply] = $this->recv($this->timeout);
            if (!is_object($reply)) {
                break;
            }
            yield $reply;
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
        $streamId            = $this->streamId;
        $this->cancelled     = true;
        $this->ended         = true;
        $this->status        = new Status(StatusCode::CANCELLED, 'cancelled locally');
        $this->streamId      = 0;
        $this->pendingFrames = [];
        if ($streamId > 0) {
            $this->client->abandon($streamId);
        }
    }

    /**
     * @return array{0: mixed, 1: int, 2: mixed}
     */
    private function emitPending(): array
    {
        $item = array_shift($this->pendingFrames);
        if ($item === null) {
            $this->ended = true;
            return Parser::parseToResultArray(false, $this->deserialize);
        }
        $response = ResponseCopy::withFrame($item['response'], $item['frame']);
        if ($item['done']) {
            $this->streamId = 0;
            $this->ended    = true;
            $this->observe($response, Client::RECV_OK);
        }

        return Parser::parseToResultArray($response, $this->deserialize);
    }

    /**
     * @param \Swoole\Http2\Response|false|null $response
     */
    private function observe($response, int $failure = Client::RECV_OK): void
    {
        $this->trailers = Trailers::fromResponse($response);
        if ($this->cancelled) {
            return;
        }
        if ($failure === Client::RECV_TIMEOUT) {
            $this->status = new Status(StatusCode::DEADLINE_EXCEEDED, 'Deadline exceeded');
            return;
        }
        if (!$response && !$this->ended) {
            // A missing response mid-stream is a transport-level failure, not a gRPC status.
            $this->status = new Status(StatusCode::UNAVAILABLE, 'No response');
            return;
        }
        $this->status = Status::fromResponse($response);
    }
}
