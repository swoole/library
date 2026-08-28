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
 * Class RedisConfigTest
 *
 * @internal
 * @covers \Swoole\Database\RedisConfig
 */
class RedisConfigTest extends TestCase
{
    public function testAuthDefaultsToAnEmptyString(): void
    {
        $this->assertSame('', (new RedisConfig())->getAuth());
    }

    public function testWithAuthAcceptsAPassword(): void
    {
        $config = new RedisConfig();

        $this->assertSame($config, $config->withAuth('secret'), 'withAuth() is a fluent setter');
        $this->assertSame('secret', $config->getAuth());
    }

    public function testWithAuthAcceptsUsernameAndPassword(): void
    {
        $config = new RedisConfig();

        $this->assertSame($config, $config->withAuth(['user', 'secret']), 'withAuth() is a fluent setter');
        $this->assertSame(['user', 'secret'], $config->getAuth());
    }

    public function testWithAuthCanSwitchBetweenBothForms(): void
    {
        $config = (new RedisConfig())->withAuth(['user', 'secret'])->withAuth('secret');

        $this->assertSame('secret', $config->getAuth());
    }
}
