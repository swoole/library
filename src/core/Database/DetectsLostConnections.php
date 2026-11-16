<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Database;

class DetectsLostConnections
{
    private const DRIVER_ERRORS = [2002, 2006, 2013, 2024, 2055, 3113, 3114];

    /** Specific transport diagnostics, never generic words such as "timed out" or "went away". */
    private const ERROR_MESSAGES = [
        'server has gone away',
        'no connection to the server',
        'lost connection to mysql server',
        'server closed the connection unexpectedly',
        'ssl connection has been closed unexpectedly',
        'error writing data to the connection',
        'decryption failed or bad record mac',
        'physical connection is not usable',
        'communication link failure',
        'connection is no longer usable',
        'ssl syscall error: eof detected',
        'ssl error: unexpected eof',
        'ssl error: sslv3 alert unexpected message',
        'ssl error: ssl/tls alert unexpected message',
        'unrecognized ssl error code:',
        'channel connection is closed',
    ];

    public static function causedByLostConnection(\Throwable $e): bool
    {
        if ($e instanceof \PDOException && is_array($e->errorInfo)) {
            return self::causedByErrorInfo($e->errorInfo);
        }
        if ($e instanceof \mysqli_sql_exception) {
            return self::matches($e->getMessage(), $e->getSqlState(), $e->getCode());
        }
        $code  = (string) $e->getCode();
        $state = preg_match('/^[A-Z0-9]{5}$/i', $code) ? $code : '';
        return self::matches($e->getMessage(), $state, $state === '' ? (int) $code : 0);
    }

    /**
     * @param array{0?: string, 1?: int|string|null, 2?: string|null}|array $info
     */
    public static function causedByErrorInfo(array $info): bool
    {
        return self::matches((string) ($info[2] ?? ''), (string) ($info[0] ?? ''), (int) ($info[1] ?? 0));
    }

    private static function matches(string $message, string $state, int $driverError): bool
    {
        if (preg_match('/^SQLSTATE\[([A-Z0-9]{5})\]/i', $message, $matches)) {
            $state = strtoupper($matches[1]);
            if ($driverError === 0 && preg_match('/^SQLSTATE\[[^]]+\](?: \[(\d+)\]|: [^:]+: (\d+)\b)/', $message, $codes)) {
                $driverError = (int) ($codes[1] !== '' ? $codes[1] : $codes[2]);
            }
        }
        $state = strtoupper($state);
        // Authentication, constraint and SQL errors are not transport failures, even when their text quotes one.
        if (in_array($driverError, [1044, 1045], true) || stripos($message, 'access denied for user') !== false) {
            return false;
        }
        if (str_starts_with($state, '08')) {
            return true;
        }
        if ($state !== '' && $state !== 'HY000') {
            return false;
        }
        if (in_array($driverError, self::DRIVER_ERRORS, true)) {
            return true;
        }
        // PostgreSQL uses driver code 7 for several transport diagnostics; other explicit error numbers win.
        if ($driverError !== 0 && $driverError !== 7) {
            return false;
        }
        foreach (self::ERROR_MESSAGES as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }
        return (bool) preg_match('/^(?:broken pipe|connection lost|connection reset by peer|ssl: (?:handshake|operation|connection) timed out)$/i', trim($message))
            || (bool) preg_match('/^PDO::\w+\(\): Send of \d+ bytes failed with errno=(?:32|54|104|110)\b/i', $message)
            || (bool) preg_match('/\bORA-0311[34]\b/i', $message);
    }
}
