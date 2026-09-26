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

use Swoole\Tests\DatabaseTestCase;
use Swoole\Tests\HookFlagsTrait;

/**
 * What these tests check is only observable under a mysqli report mode that returns false instead of throwing.
 * The report mode is process-wide, so switching it here would change what every other mysqli test running at
 * the same time sees; this file runs in the serial suite for that reason.
 *
 * @internal
 * @covers \Swoole\Database\MysqliProxy
 */
class MysqliReportModeTest extends DatabaseTestCase
{
    use HookFlagsTrait;

    /**
     * A method outside the proxy's retry list that fails on a dead connection returns false under a report mode
     * that does not throw, and the proxy used to pass that false through as if there were nothing to return, so
     * the lost connection went unnoticed. stat() stands in for store_result(), next_result() and the like: it
     * talks to the server on every call, and unlike those it cannot be pre-empted by rows already received.
     */
    public function testLostConnectionInMethodOutsideTheRetryListIsReported(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        $driver     = new \mysqli_driver();
        $reportMode = $driver->report_mode;
        try {
            $driver->report_mode = MYSQLI_REPORT_OFF;
            self::coRun(function () {
                $pool   = self::getMysqliPool(1);
                $mysqli = $pool->get();
                self::assertNotFalse($mysqli->stat(), 'stat() works on a live connection.');

                self::killMysqliConnection($mysqli);

                try {
                    $mysqli->stat();
                    self::fail('The lost connection is reported instead of a bare false.');
                } catch (MysqliException $e) {
                    self::assertContains($e->getCode(), MysqliProxy::IO_ERRORS);
                }
                self::assertSame(0, $mysqli->getRound(), 'stat() is not retried, so there is no reconnect either.');

                $pool->put(null);
                $pool->close();
            });
        } finally {
            $driver->report_mode = $reportMode;
            self::restoreHookFlags();
        }
    }
}
