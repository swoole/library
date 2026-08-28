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
 * Class RedisPoolTest
 *
 * @internal
 * @covers \Swoole\Database\RedisPool
 */
class RedisPoolTest extends DatabaseTestCase
{
    use HookFlagsTrait;

    /**
     * A password-only auth authenticates against the default user, as it did before array auth was supported.
     */
    public function testAuthWithPassword(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        self::coRun(function () {
            $admin = new \Redis();
            $admin->connect(REDIS_SERVER_HOST, REDIS_SERVER_PORT);
            $admin->config('SET', 'requirepass', 'default_user_secret');
            try {
                $config = (new RedisConfig())
                    ->withHost(REDIS_SERVER_HOST)
                    ->withPort(REDIS_SERVER_PORT)
                    ->withAuth('default_user_secret')
                ;
                $pool  = new RedisPool($config, 1);
                $redis = $pool->get();

                $this->assertSame('default', $redis->rawCommand('ACL', 'WHOAMI'));
                $this->assertTrue($redis->set('swoole:library:test:auth:password', 'ok'));
                $this->assertSame('ok', $redis->get('swoole:library:test:auth:password'));
                $redis->del('swoole:library:test:auth:password');

                $pool->put($redis);
                $pool->close();
            } finally {
                // The admin connection was established before the password was set, thus still authorized to unset it.
                $admin->config('SET', 'requirepass', '');
                $admin->close();
            }
        });
        self::restoreHookFlags();
    }

    /**
     * An array auth of [username, password] authenticates against a Redis ACL user.
     */
    public function testAuthWithUsernameAndPassword(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        self::coRun(function () {
            $admin = new \Redis();
            $admin->connect(REDIS_SERVER_HOST, REDIS_SERVER_PORT);
            $admin->rawCommand('ACL', 'SETUSER', 'swoole_tester', 'on', '>acl_user_secret', '~*', '&*', '+@all');
            try {
                $config = (new RedisConfig())
                    ->withHost(REDIS_SERVER_HOST)
                    ->withPort(REDIS_SERVER_PORT)
                    ->withAuth(['swoole_tester', 'acl_user_secret'])
                ;
                $pool  = new RedisPool($config, 1);
                $redis = $pool->get();

                $this->assertSame('swoole_tester', $redis->rawCommand('ACL', 'WHOAMI'));
                $this->assertTrue($redis->set('swoole:library:test:auth:acl', 'ok'));
                $this->assertSame('ok', $redis->get('swoole:library:test:auth:acl'));
                $redis->del('swoole:library:test:auth:acl');

                $pool->put($redis);
                $pool->close();
            } finally {
                $admin->rawCommand('ACL', 'DELUSER', 'swoole_tester');
                $admin->close();
            }
        });
        self::restoreHookFlags();
    }

    /**
     * An ACL user with a wrong password must not be able to get a connection out of the pool.
     */
    public function testAuthWithBadCredentials(): void
    {
        self::saveHookFlags();
        self::setHookFlags(SWOOLE_HOOK_ALL);
        self::coRun(function () {
            $config = (new RedisConfig())
                ->withHost(REDIS_SERVER_HOST)
                ->withPort(REDIS_SERVER_PORT)
                ->withAuth(['no_such_user', 'wrong_secret'])
            ;
            $pool = new RedisPool($config, 1);
            try {
                $pool->get();
                $this->fail('An exception should be thrown when authenticating with bad credentials.');
            } catch (\RedisException $e) {
                $this->assertStringContainsStringIgnoringCase('WRONGPASS', $e->getMessage());
            } finally {
                $pool->close();
            }
        });
        self::restoreHookFlags();
    }
}
