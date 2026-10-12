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
class RegistryIntegrationTest extends TestCase
{
    public function testManagedRedisAndConsulRenewAndStop(): void
    {
        self::coRun(function () {
            foreach ([new Redis(REDIS_SERVER_URL), new Consul(CONSUL_AGENT_URL)] as $resolver) {
                $name         = 'managed-' . bin2hex(random_bytes(8));
                $registration = $resolver->register($name, '127.0.0.1', 8081, [], 0.02);
                try {
                    self::assertNotNull($resolver->getCluster($name));
                    $first = $registration->getLastHeartbeat();
                    Coroutine::sleep(0.08);
                    self::assertGreaterThan($first, $registration->getLastHeartbeat());
                    self::assertNull($registration->getLastError());
                    self::assertNotNull($resolver->getCluster($name));
                } finally {
                    $registration->stop();
                }
                self::assertNull($resolver->getCluster($name));
            }
        });
    }

    public function testConsulMaintenanceExcludesAndRestoresService(): void
    {
        self::coRun(function () {
            $r    = new Consul(CONSUL_AGENT_URL);
            $name = 'swoole-test-' . bin2hex(random_bytes(8));
            try {
                self::assertTrue($r->join($name, '127.0.0.1', 8080, ['check' => ['TTL' => '30s', 'Status' => 'passing']]));
                self::assertSame(1, $r->getCluster($name)->count());
                self::assertTrue($r->heartbeat($name, '127.0.0.1', 8080));
                self::assertTrue($r->updateHealth('service:swoole_service_' . $name . '_127.0.0.1:8080', 'critical'));
                self::assertNull($r->getCluster($name));
                self::assertTrue($r->heartbeat($name, '127.0.0.1', 8080));
                self::assertSame(1, $r->getCluster($name)->count());
                self::assertTrue($r->enableMaintenanceMode($name, '127.0.0.1', 8080));
                self::assertNull($r->getCluster($name));
                self::assertTrue($r->enableMaintenanceMode($name, '127.0.0.1', 8080, false));
                self::assertSame(1, $r->getCluster($name)->count());
            } finally {
                $r->leave($name, '127.0.0.1', 8080);
            }
            self::assertNull($r->getCluster($name));
        });
    }

    public function testNacosHeartbeatAndDeregistrationUseTheSameGroupAndCluster(): void
    {
        self::coRun(function () {
            $r    = (new Nacos(NACOS_SERVER_URL))->withGroup('SWOOLE_TEST')->withCluster('blue');
            $name = 'swoole-test-' . bin2hex(random_bytes(8));
            try {
                self::assertTrue($r->join($name, '127.0.0.1', 8080, ['weight' => 0.5]));
                self::assertGreaterThan(0, $r->heartbeat($name, '127.0.0.1', 8080, ['weight' => 0.5]));
                // Nacos' push/cache update is asynchronous, so wait for visibility with a bounded deadline.
                $deadline = microtime(true) + 3;
                do {
                    $cluster = $r->getCluster($name);
                    if ($cluster !== null) {
                        break;
                    }
                    Coroutine::sleep(0.02);
                } while (microtime(true) < $deadline);
                self::assertNotNull($cluster);
                $node = $cluster->pop();
                self::assertSame('127.0.0.1', $node['host']);
                self::assertSame(8080, $node['port']);
                self::assertSame(0.5, $node['weight']);
            } finally {
                $r->leave($name, '127.0.0.1', 8080);
            }
        });
    }
}
