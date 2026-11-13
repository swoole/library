<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka;

use Swoole\Coroutine;

/**
 * Append-only log of the Kafka client's blocking calls.
 *
 * Off unless SWOOLE_KAFKA_TRACE names a file. Each line is written through before the
 * call that may not return, so a CI step killed by its timeout still shows the last one.
 */
final class Trace
{
    /**
     * null until the first call resolves the path. false when tracing is off.
     */
    private static string|false|null $file = null;

    public static function log(string $message): void
    {
        $file = self::file();
        if ($file === null) {
            return;
        }
        file_put_contents(
            $file,
            sprintf("%.6f cid=%d %s\n", microtime(true), Coroutine::getCid(), $message),
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Drop the cached path. The next call reads SWOOLE_KAFKA_TRACE again.
     */
    public static function reset(): void
    {
        self::$file = null;
    }

    private static function file(): ?string
    {
        if (self::$file === null) {
            $path = getenv('SWOOLE_KAFKA_TRACE');
            if ($path === false || $path === '') {
                self::$file = false;

                return null;
            }
            $dir = \dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                self::$file = false;

                return null;
            }
            self::$file = $path;
        }

        return self::$file === false ? null : self::$file;
    }
}
