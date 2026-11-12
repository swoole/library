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

/**
 * The gRPC status codes, as defined by grpc/status.h and shared by every language implementation.
 *
 * The OK code is 0; all other codes describe a failed RPC. Codes other than OK are returned in
 * the response trailers as "grpc-status".
 */
final class StatusCode
{
    public const OK = 0;

    public const CANCELLED = 1;

    public const UNKNOWN = 2;

    public const INVALID_ARGUMENT = 3;

    public const DEADLINE_EXCEEDED = 4;

    public const NOT_FOUND = 5;

    public const ALREADY_EXISTS = 6;

    public const PERMISSION_DENIED = 7;

    public const RESOURCE_EXHAUSTED = 8;

    public const FAILED_PRECONDITION = 9;

    public const ABORTED = 10;

    public const OUT_OF_RANGE = 11;

    public const UNIMPLEMENTED = 12;

    public const INTERNAL = 13;

    public const UNAVAILABLE = 14;

    public const DATA_LOSS = 15;

    public const UNAUTHENTICATED = 16;

    /** @var array<int, string> */
    private const NAMES = [
        self::OK                  => 'OK',
        self::CANCELLED           => 'CANCELLED',
        self::UNKNOWN             => 'UNKNOWN',
        self::INVALID_ARGUMENT    => 'INVALID_ARGUMENT',
        self::DEADLINE_EXCEEDED   => 'DEADLINE_EXCEEDED',
        self::NOT_FOUND           => 'NOT_FOUND',
        self::ALREADY_EXISTS      => 'ALREADY_EXISTS',
        self::PERMISSION_DENIED   => 'PERMISSION_DENIED',
        self::RESOURCE_EXHAUSTED  => 'RESOURCE_EXHAUSTED',
        self::FAILED_PRECONDITION => 'FAILED_PRECONDITION',
        self::ABORTED             => 'ABORTED',
        self::OUT_OF_RANGE        => 'OUT_OF_RANGE',
        self::UNIMPLEMENTED       => 'UNIMPLEMENTED',
        self::INTERNAL            => 'INTERNAL',
        self::UNAVAILABLE         => 'UNAVAILABLE',
        self::DATA_LOSS           => 'DATA_LOSS',
        self::UNAUTHENTICATED     => 'UNAUTHENTICATED',
    ];

    /** Codes for which a transparent retry is generally safe (transient failures). */
    private const RETRYABLE = [
        self::UNAVAILABLE        => true,
        self::RESOURCE_EXHAUSTED => true,
        self::DEADLINE_EXCEEDED  => true,
    ];

    private function __construct()
    {
    }

    public static function name(int $code): string
    {
        return self::NAMES[$code] ?? 'UNKNOWN_CODE_' . $code;
    }

    /**
     * Whether a client may transparently retry a call that failed with this code. Follows the
     * grpc-go / etcd clientv3 convention; callers are still responsible for idempotency.
     */
    public static function isRetryable(int $code): bool
    {
        return isset(self::RETRYABLE[$code]);
    }
}
