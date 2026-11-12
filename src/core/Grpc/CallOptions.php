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

use Swoole\Grpc\Internal\Timeout;

/**
 * Opt-in per-call options layered on top of the existing $options array passed by generated
 * stubs. Every field defaults to a value that reproduces the current behaviour, so calls that
 * do not use the new keys are byte-for-byte unchanged.
 *
 * Recognized keys:
 *  - 'timeout' / 'deadline': float seconds (deadline may be an absolute Unix timestamp) -> grpc-timeout
 *  - 'metadata': array merged with the call's $metadata
 *  - 'compression': 'gzip' or 'identity' (default identity)
 *  - 'grpc.throw_on_error': bool, throw RpcException on a non-OK status (default false)
 *  - 'headers': raw extra headers (legacy, still honoured)
 *  - 'wait_for_ready': bool (reserved for the connection layer)
 */
final class CallOptions
{
    private ?float $timeout = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    private string $compression = 'identity';

    private bool $throwOnError = false;

    private bool $waitForReady = false;

    /** @var array<string, string> */
    private array $headers = [];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $metadata the call's metadata argument
     * @param array<string, mixed> $options the call's options argument
     */
    public static function parse(array $metadata, array $options): self
    {
        $self = new self();

        if (isset($options['timeout']) && is_numeric($options['timeout'])) {
            $self->timeout = (float) $options['timeout'];
        } elseif (isset($options['deadline']) && is_numeric($options['deadline'])) {
            $deadline = (float) $options['deadline'];
            // Heuristic: large values are absolute Unix timestamps, small ones are durations.
            $self->timeout = $deadline > 1e9 ? max(0.0, $deadline - microtime(true)) : $deadline;
        }

        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $metadata = array_merge($metadata, $options['metadata']);
        }
        $self->metadata = $metadata;

        if (isset($options['compression']) && is_string($options['compression'])) {
            $self->compression = strtolower($options['compression']);
        }

        $self->throwOnError = (bool) ($options['grpc.throw_on_error'] ?? false);
        $self->waitForReady = (bool) ($options['wait_for_ready'] ?? false);

        if (isset($options['headers']) && is_array($options['headers'])) {
            $extra      = $options['headers'];
            $normalized = [];
            foreach ($extra as $key => $value) {
                $normalized[strtolower((string) $key)] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
            $self->headers = $normalized;
        }

        return $self;
    }

    /**
     * Build the extra HTTP/2 headers for this call. Only non-empty opt-in values are added, so
     * a call using none of the new keys produces an empty array (no behaviour change).
     *
     * @return array<string, string>
     */
    public function buildHeaders(): array
    {
        $headers = $this->headers;

        $normalizedMetadata = Metadata::normalize($this->metadata);
        foreach ($normalizedMetadata as $key => $value) {
            $headers[$key] = $value;
        }

        if ($this->timeout !== null && $this->timeout >= 0) {
            $headers['grpc-timeout'] = Timeout::encode($this->timeout);
        }

        if ($this->compression !== '' && $this->compression !== 'identity') {
            $headers['grpc-encoding']        = $this->compression;
            $headers['grpc-accept-encoding'] = $this->compression;
        }

        return $headers;
    }

    public function getTimeout(): ?float
    {
        return $this->timeout;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCompression(): string
    {
        return $this->compression;
    }

    public function shouldThrowOnError(): bool
    {
        return $this->throwOnError;
    }

    public function shouldWaitForReady(): bool
    {
        return $this->waitForReady;
    }
}
