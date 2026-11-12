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

/**
 * An active call whose messages are exchanged over one HTTP/2 stream.
 */
class StreamingCall extends BaseCall
{
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
        if ($this->streamId <= 0) {
            $response = false;
        } else {
            $response = $this->client->recv($this->streamId, $timeout);
            if (!$this->client->isStreamExist($this->streamId)) {
                // The final response (or a timeout) unregistered the stream.
                $this->streamId = 0;
            }
        }

        return Parser::parseToResultArray($response, $this->deserialize);
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
}
