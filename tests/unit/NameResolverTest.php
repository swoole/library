<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole;

use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\NameResolver\Consul
 * @covers \Swoole\NameResolver\Nacos
 * @covers \Swoole\NameResolver\Redis
 */
class NameResolverTest extends TestCase
{
    public function testRedis(): void
    {
        $ns = new NameResolver\Redis(REDIS_SERVER_URL);
        $this->fun1($ns);
    }

    public function testConsul(): void
    {
        $ns = new NameResolver\Consul(CONSUL_AGENT_URL);
        $this->fun1($ns);
    }

    public function testNacos(): void
    {
        $ns = new NameResolver\Nacos(NACOS_SERVER_URL);
        $this->fun1($ns);
    }

    public function testLookup(): void
    {
        if (!function_exists('swoole_name_resolver_lookup')) {
            $this->markTestSkipped('Swoole v4.9 or later is required.');
        }
        $count = 0;
        $ns    = new NameResolver\Redis(REDIS_SERVER_URL);
        $ns->withFilter(function ($name) use (&$count) {
            $count++;
            return swoole_string($name)->endsWith('.service');
        });
        swoole_name_resolver_add($ns);
        $domain = 'localhost';
        $this->assertEquals(swoole_name_resolver_lookup($domain, new NameResolver\Context()), gethostbyname($domain));
        $this->assertEquals(1, $count);
        $this->assertTrue(swoole_name_resolver_remove($ns));
    }

    public function testRedisCo(): void
    {
        self::coRun(function () {
            $ns = new NameResolver\Redis(REDIS_SERVER_URL);
            $this->fun1($ns);
        });
    }

    public function testConsulCo(): void
    {
        self::coRun(function () {
            $ns = new NameResolver\Consul(CONSUL_AGENT_URL);
            $this->fun1($ns);
        });
    }

    public function testNacosCo(): void
    {
        self::coRun(function () {
            $ns = new NameResolver\Nacos(NACOS_SERVER_URL);
            $this->fun1($ns);
        });
    }

    private function fun1(NameResolver $ns): void
    {
        $service_name = uniqid() . '.service';
        $ip           = '127.0.0.1';
        $port         = random_int(10000, 65535);
        $this->assertTrue($ns->join($service_name, $ip, $port));

        $rs = $ns->getCluster($service_name);
        $this->assertEquals(1, $rs->count());
        $node = $rs->pop();
        $this->assertNotEmpty($node);
        $this->assertEquals($ip, $node['host']);
        $this->assertEquals($port, $node['port']);

        $this->assertTrue($ns->leave($service_name, $ip, $port));
    }
}
