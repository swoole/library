<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\NameResolver;

use Swoole\Coroutine;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class RedisTest extends TestCase
{
    public function testLegacyMembersRemainReadable(): void
    {
        $prefix = 'swoole:test:resolver:' . bin2hex(random_bytes(8)) . ':';
        $r      = new Redis(REDIS_SERVER_URL, $prefix);
        self::coRun(function () use ($r, $prefix) {
            $info = parse_url(REDIS_SERVER_URL);
            $raw  = new \Redis();
            $raw->connect($info['host'], $info['port'] ?? 6379);
            try {
                $raw->sAdd($prefix . 'legacy', '::1:8080');
                self::assertSame('::1', $r->getCluster('legacy')->pop()['host']);
                $r->join('legacy', '::1', 8080, ['weight' => 0.5]);
                self::assertSame(1, $r->getCluster('legacy')->count());
                self::assertSame(0.5, $r->getCluster('legacy')->pop()['weight']);
                $r->leave('legacy', '::1', 8080);
                self::assertFalse($raw->exists($prefix . 'legacy') > 0);
            } finally {
                $raw->del($prefix . 'legacy');
                $raw->close();
            }
        });
    }

    public function testAclCredentialsAndDatabaseSelection(): void
    {
        self::coRun(function () {
            $info = parse_url(REDIS_SERVER_URL);
            $raw  = new \Redis();
            $raw->connect($info['host'], $info['port'] ?? 6379);
            $user     = 'swoole-test-' . bin2hex(random_bytes(8));
            $password = 'p+ /?' . bin2hex(random_bytes(8));
            $prefix   = 'swoole:test:resolver:' . $user . ':';
            $r        = new Redis('redis://' . $user . ':' . rawurlencode($password) . '@' . $info['host'] . ':' . ($info['port'] ?? 6379) . '/13', $prefix);
            try {
                self::assertTrue($raw->acl('SETUSER', $user, 'on', '>' . $password, '+@all', '~' . $prefix . '*'));
                self::assertTrue($r->join('auth', '127.0.0.1', 8080));
                self::assertSame('127.0.0.1', $r->getCluster('auth')->pop()['host']);
                self::assertSame(0, $raw->exists($prefix . 'auth'));
                $r->leave('auth', '127.0.0.1', 8080);
            } finally {
                $raw->acl('DELUSER', $user);
                $raw->close();
            }
        });
    }

    public function testIpv6WeightsAndLeases(): void
    {
        $resolver = new Redis(REDIS_SERVER_URL, 'swoole:test:resolver:' . bin2hex(random_bytes(8)) . ':');
        $name     = 'lease';
        self::coRun(function () use ($resolver, $name) {
            try {
                self::assertTrue($resolver->join($name, '::1', 8080, ['weight' => 0.5, 'ttl' => 1]));
                self::assertTrue($resolver->join($name, '127.0.0.1', 8081, ['weight' => 200]));
                self::assertSame(2, $resolver->getCluster($name)->count());
                Coroutine::sleep(1.1);
                $node = $resolver->getCluster($name)->pop();
                self::assertSame('127.0.0.1', $node['host']);
                self::assertEquals(200, $node['weight']);
                self::assertTrue($resolver->join($name, '::1', 8080, ['ttl' => 5]));
                self::assertTrue($resolver->heartbeat($name, '::1', 8080, ['ttl' => 10]));
                self::assertSame(2, $resolver->getCluster($name)->count());
            } finally {
                $resolver->leave($name, '::1', 8080);
                $resolver->leave($name, '127.0.0.1', 8081);
            }
            self::assertNull($resolver->getCluster($name));
        });
    }

    public function testLookupCacheIsInvalidatedByRegistration(): void
    {
        $r = new Redis(REDIS_SERVER_URL, 'swoole:test:resolver:' . bin2hex(random_bytes(8)) . ':');
        self::coRun(function () use ($r) {
            try {
                self::assertNull($r->lookup('test'));
                $r->join('test', '127.0.0.1', 80);
                self::assertSame('127.0.0.1', $r->lookup('test')['host']);
                $r->leave('test', '127.0.0.1', 80);
                self::assertNull($r->lookup('test'));
            } finally {
                $r->leave('test', '127.0.0.1', 80);
            }
        });
    }
}
