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
 * @covers \Swoole\Database\PDOConfig
 * @covers \Swoole\Database\PDOPool
 */
class PDOConfigTest extends TestCase
{
    public function testZeroSocketPathIsPresent(): void
    {
        self::assertFalse((new PDOConfig())->hasUnixSocket());
        self::assertFalse((new PDOConfig())->withUnixSocket('')->hasUnixSocket());
        self::assertTrue((new PDOConfig())->withUnixSocket('0')->hasUnixSocket());
    }

    /**
     * @dataProvider unsafeComponents
     */
    public function testDsnRejectsInjectedParameters(string $driver, string $setter, string $value): void
    {
        $config = (new PDOConfig())->withDriver($driver);
        $config->{$setter}($value);
        $pool   = new PDOPool($config, 1);
        $method = new \ReflectionMethod($pool, 'createDSN');
        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($pool, $driver);
    }

    public static function unsafeComponents(): iterable
    {
        foreach (['mysql', 'pgsql', 'oci'] as $driver) {
            foreach (['withHost', 'withDbname', 'withCharset', 'withUnixSocket'] as $setter) {
                yield $driver . ':' . $setter => [$driver, $setter, 'value;host=other'];
            }
        }
        yield 'Oracle service separator' => ['oci', 'withDbname', 'service:1521/other'];
        yield 'Oracle host separator' => ['oci', 'withHost', 'host/other'];
        yield 'nul' => ['mysql', 'withHost', "host\0other"];
    }

    public function testPostgresQuotesConnectionValues(): void
    {
        $config = (new PDOConfig())->withDriver('pgsql')->withHost('/tmp/db socket')->withDbname("test' sslmode=disable");
        $pool   = new PDOPool($config, 1);
        $dsn    = (new \ReflectionMethod($pool, 'createDSN'))->invoke($pool, 'pgsql');
        self::assertSame("pgsql:host='/tmp/db socket';port=3306;dbname='test\\' sslmode=disable'", $dsn);
    }
}
