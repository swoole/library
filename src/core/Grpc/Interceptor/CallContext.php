<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc\Interceptor;

/**
 * Immutable description of an RPC, passed through the interceptor chain. Mirrors the
 * information grpc-go's invoker / grpc-node's InterceptingCall expose to interceptors.
 */
final class CallContext
{
    /** Unary or one of the streaming kinds. */
    public const UNARY = 'unary';

    public const CLIENT_STREAMING = 'client_streaming';

    public const SERVER_STREAMING = 'server_streaming';

    public const BIDI_STREAMING = 'bidi_streaming';

    private string $method;

    private string $type;

    /** @var array<string, mixed> */
    private array $metadata;

    /** @var array<string, mixed> */
    private array $options;

    /** @var mixed */
    private $argument;

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $options
     * @param mixed $argument
     */
    public function __construct(string $method, string $type = self::UNARY, array $metadata = [], array $options = [], $argument = null)
    {
        $this->method   = $method;
        $this->type     = $type;
        $this->metadata = $metadata;
        $this->options  = $options;
        $this->argument = $argument;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return mixed
     */
    public function getArgument()
    {
        return $this->argument;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $clone           = clone $this;
        $clone->metadata = $metadata;
        return $clone;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): self
    {
        $clone          = clone $this;
        $clone->options = $options;
        return $clone;
    }
}
