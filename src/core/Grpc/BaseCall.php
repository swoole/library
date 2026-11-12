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
 * Shared state of an active streaming call.
 */
class BaseCall
{
    /** @var Client|VirtualClient */
    protected $client;

    protected string $method = '';

    /** @var mixed */
    protected $deserialize;

    protected int $streamId = 0;

    /** @var array<string, string> */
    protected array $headers = [];

    /**
     * @param Client|VirtualClient $client
     */
    public function setClient($client): void
    {
        $this->client = $client;
    }

    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    /**
     * @param mixed $deserialize
     */
    public function setDeserialize($deserialize): void
    {
        $this->deserialize = $deserialize;
    }

    /**
     * @param array<string, string> $headers
     */
    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    public function getStreamId(): int
    {
        return $this->streamId;
    }

    public function setStreamId(int $streamId): void
    {
        $this->streamId = $streamId;
    }
}
