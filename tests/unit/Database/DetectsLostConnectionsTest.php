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

use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Database\DetectsLostConnections
 */
class DetectsLostConnectionsTest extends TestCase
{
    /**
     * @dataProvider dataLostConnectionMessages
     */
    public function testLostConnectionMessages(string $message): void
    {
        self::assertTrue(DetectsLostConnections::causedByLostConnection(new \PDOException($message)), $message);
    }

    public static function dataLostConnectionMessages(): array
    {
        return [
            // The classic ones.
            ['SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
            ['SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query'],
            ['SQLSTATE[HY000] [2002] Connection refused'],
            ['SQLSTATE[08006] [7] could not connect to server: Connection refused Is the server running on host "db"'],
            // SSL failures during a query, not only at connect time: the entries are not bound to a connect-time prefix.
            ['SQLSTATE[HY000]: General error: 7 SSL error: sslv3 alert unexpected message'],
            ['SQLSTATE[HY000]: General error: 7 SSL error: ssl/tls alert unexpected message'],
            ['SQLSTATE[HY000]: General error: 7 unrecognized SSL error code: 1'],
            // PostgreSQL hot standby and PlanetScale PostgreSQL / pg_bouncer.
            ['SQLSTATE[40001]: Serialization failure: 7 ERROR:  canceling statement due to conflict with recovery'],
            ['SQLSTATE[08006] [7] failed to connect to upstream'],
            ['SQLSTATE[08006] [7] failed to send startup message'],
            ['SQLSTATE[08006] [7] failed to read startup message'],
            ['SQLSTATE[08006] [7] no primary available for branch'],
            ['SQLSTATE[08006] [7] no replica available for branch'],
            ['SQLSTATE[08006] [7] no running members available for branch'],
            // This library's own entries.
            ['SQLSTATE[HY000]: General error: 3113 ORA-03113: end-of-file on communication channel'],
            ['PDO::prepare(): Send of 77 bytes failed with errno=110 Operation timed out'],
            ['SQLSTATE[HY000]: General error: 2024 Error reading result set\'s header'],
        ];
    }

    /**
     * @dataProvider dataOtherMessages
     */
    public function testOtherMessagesAreNotLostConnections(string $message): void
    {
        self::assertFalse(DetectsLostConnections::causedByLostConnection(new \PDOException($message)), $message);
    }

    public static function dataOtherMessages(): array
    {
        return [
            ['SQLSTATE[42S02]: Base table or view not found: 1146 Table \'test.missing\' doesn\'t exist'],
            ['SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'1\' for key \'PRIMARY\''],
            ['SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax'],
            ['SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'],
        ];
    }
}
