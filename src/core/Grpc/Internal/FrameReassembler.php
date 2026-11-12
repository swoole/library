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

use Swoole\Grpc\Compression\Compressor;
use Swoole\Grpc\Parser;
use Swoole\Http2\Response;

/**
 * Reassembles gRPC messages across multiple HTTP/2 DATA fragments.
 *
 * Swoole's pipelined recv() hands back incremental data chunks that may split a 5-byte frame
 * header or payload at any byte, or pack several frames into one chunk. This class accumulates
 * bytes and emits complete, decompressed, identity-framed messages ready for
 * Parser::deserializeMessage(). It stays purely byte-level (no Status dependency).
 */
final class FrameReassembler
{
    private string $buffer = '';

    /** @var array<int, Compressor> keyed by compression flag */
    private array $compressors;

    private bool $done = false;

    /** @param array<int, Compressor> $compressors */
    public function __construct(array $compressors = [])
    {
        $this->compressors = $compressors;
    }

    /**
     * Feed one HTTP/2 response. Returns the list of complete frames decoded so far plus the
     * stream state.
     *
     * @param Response|false|null $response a false/absent response means timeout or cancellation
     * @return array{frames: list<string>, done: bool, response: Response|false|null}
     */
    public function push($response): array
    {
        if (!$response instanceof Response) {
            // The read side ended without a final frame (timeout/close/cancel).
            $this->done = true;
            return ['frames' => $this->drain(), 'done' => true, 'response' => false];
        }

        if (is_string($response->data) && $response->data !== '') {
            $this->buffer .= $response->data;
        }

        $frames = $this->drain();

        // pipeline=false marks the terminal response (END_STREAM). A grpc-status in trailers
        // also ends the call (Trailers-Only). Intermediate pipeline fragments keep it open.
        $trailers  = Trailers::fromResponse($response);
        $hasStatus = isset($trailers['grpc-status']);
        $ended     = !$response->pipeline || $hasStatus;
        if ($ended) {
            $this->done = true;
        }

        return ['frames' => $frames, 'done' => $this->done, 'response' => $response];
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function reset(): void
    {
        $this->buffer = '';
        $this->done   = false;
    }

    /**
     * Extract as many complete frames as the current buffer allows.
     *
     * @return list<string>
     */
    private function drain(): array
    {
        $frames = [];
        while (strlen($this->buffer) >= Frame::HEADER_LENGTH) {
            [, , $total] = Frame::header($this->buffer);
            if (strlen($this->buffer) < $total) {
                break; // half frame: wait for more bytes
            }
            $frame        = substr($this->buffer, 0, $total);
            $this->buffer = substr($this->buffer, $total);
            // Re-pack as an identity frame so Parser::deserializeMessage() can consume it.
            $frames[] = Parser::pack(Frame::decode($frame, $this->compressors));
        }

        return $frames;
    }
}
