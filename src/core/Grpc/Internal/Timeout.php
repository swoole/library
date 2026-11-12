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

/**
 * Encodes/decodes the gRPC "grpc-timeout" header value.
 *
 * Per the gRPC over HTTP/2 spec, a timeout is an integer (at most 8 digits) followed by a unit:
 * H (hour), M (minute), S (second), m (millisecond), u (microsecond), n (nanosecond).
 * Like grpc-go, encode() picks the largest unit that represents the duration exactly as an
 * integer, so 1.5s becomes "1500m" rather than a lossy "2S".
 */
final class Timeout
{
    /** @var array<string, float> unit => seconds per unit, coarse to fine */
    private const UNITS = [
        'H' => 3600.0,
        'M' => 60.0,
        'S' => 1.0,
        'm' => 1e-3,
        'u' => 1e-6,
        'n' => 1e-9,
    ];

    private function __construct()
    {
    }

    /**
     * Encode a duration in seconds into a grpc-timeout header value.
     */
    public static function encode(float $seconds): string
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('grpc-timeout must be non-negative');
        }
        if ($seconds === 0.0) {
            return '0n';
        }

        foreach (self::UNITS as $unit => $scale) {
            $value = $seconds / $scale;
            // Guard against float representation noise (e.g. 0.1 / 0.001 == 100.00000000000001).
            if ($value >= 1 && $value <= 99999999 && abs($value - round($value)) < 1e-6) {
                return (int) round($value) . $unit;
            }
        }

        // Fallback: express as nanoseconds, clamped to the 8-digit limit.
        $nanos = (int) min(99999999, max(1, round($seconds * 1e9)));
        return $nanos . 'n';
    }

    /**
     * Decode a grpc-timeout header value into seconds. Returns null when malformed.
     */
    public static function decode(string $value): ?float
    {
        if ($value === '') {
            return null;
        }
        $unit   = $value[strlen($value) - 1];
        $digits = substr($value, 0, -1);
        if (!isset(self::UNITS[$unit]) || $digits === '' || !ctype_digit($digits)) {
            return null;
        }

        return (float) $digits * self::UNITS[$unit];
    }
}
