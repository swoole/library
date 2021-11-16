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

use PHPUnit\Framework\TestCase;
use function Swoole\Coroutine\run;

/**
 * @internal
 * @coversNothing
 */
class NameResolverTest extends TestCase
{
    public function testRedis()
    {
        $ns = new NameResolver\Redis(REDIS_SERVER_URL);
        $this->fun1($ns);
    }

    public function testConsul()
    {
        swoole_library_set_option('http_client_driver', 'curl');
        $ns = new NameResolver\Consul(CONSUL_AGENT_URL);
        $this->fun1($ns);
    }

    public function testNacos()
    {
        swoole_library_set_option('http_client_driver', 'curl');
        $ns = new NameResolver\Nacos(NACOS_SERVER_URL);
        $this->fun1($ns);
    }

    public function testLookup()
    {
        $count = 0;
        $ns = new NameResolver\Redis(REDIS_SERVER_URL);
        $ns->withFilter(function ($name) use (&$count) {
            $count++;
            return swoole_string($name)->endsWith('.service');
        });
        swoole_name_resolver_add($ns);
        $domain = 'localhost';
        $this->assertEquals(swoole_name_resolver_lookup($domain, (new NameResolver\Context())), gethostbyname($domain));
        $this->assertEquals(1, $count);
        $this->assertTrue(swoole_name_resolver_remove($ns));
    }

    public function testRedisCo()
    {
        run(function () {
            $ns = new NameResolver\Redis(REDIS_SERVER_URL);
            $this->fun1($ns);
        });
    }

    public function testConsulCo()
    {
        run(function () {
            $ns = new NameResolver\Consul(CONSUL_AGENT_URL);
            $this->fun1($ns);
        });
    }

    public function testNacosCo()
    {
        run(function () {
            $ns = new NameResolver\Nacos(NACOS_SERVER_URL);
            $this->fun1($ns);
        });
    }

    private function fun1(NameResolver $ns)
    {
        $service_name = uniqid() . '.service';
        $ip = '127.0.0.1';
        $port = rand(10000, 65536);
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
