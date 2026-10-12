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
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\Server;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HttpServerTest extends TestCase
{
    public function testServiceNameAutomaticallyRegistersRenewsAndDeregisters(): void
    {
        self::coRun(function () {
            $name     = 'automatic-' . bin2hex(random_bytes(8)) . '.service';
            $resolver = (new Redis(REDIS_SERVER_URL))->withTimeout(0.2);
            $server   = new Server('127.0.0.1', 0);
            $server->set(['service_name'     => $name, 'name_resolver' => [$resolver],
                'service_heartbeat_interval' => 0.02, 'service_options' => ['ttl' => 0.5]]);
            $server->handle('/', fn ($request, $response) => $response->end('discovered'));
            $done = new Coroutine\Channel(1);
            Coroutine::create(function () use ($server, $done) {
                try {
                    $done->push($server->start());
                } catch (\Throwable $e) {
                    $done->push($e);
                }
            });
            $registered = false;
            try {
                $deadline = microtime(true) + 2;
                do {
                    $registered = $resolver->getCluster($name) !== null;
                    if (!$registered) {
                        Coroutine::sleep(0.002);
                    }
                } while (!$registered && microtime(true) < $deadline);
                self::assertTrue($registered);
                self::assertTrue(swoole_name_resolver_add($resolver));
                self::assertTrue(swoole_name_resolver_add($resolver));
                $client = new Client($name, 0);
                try {
                    self::assertTrue($client->get('/'));
                    self::assertSame('discovered', $client->body);
                } finally {
                    $client->close();
                }
                // Remain discoverable beyond the initial lease without application heartbeat code.
                Coroutine::sleep(0.6);
                self::assertNotNull($resolver->getCluster($name));
                self::assertTrue(swoole_name_resolver_remove($resolver));
                self::assertFalse(swoole_name_resolver_remove($resolver));
                $context = new Context(AF_INET, false);
                swoole_name_resolver_add($resolver);
                try {
                    self::assertSame('127.0.0.1', swoole_name_resolver_lookup('localhost', $context));
                } finally {
                    swoole_name_resolver_remove($resolver);
                }
            } finally {
                $server->shutdown();
                $result = $done->pop(3);
                if ($result instanceof \Throwable) {
                    throw $result;
                }
                self::assertTrue($result);
            }
            self::assertNull($resolver->getCluster($name));
        });
    }

    public function testInvalidRegistrationConfigurationPropagatesWithoutAbortingPhp(): void
    {
        self::coRun(function () {
            $server = new Server('0.0.0.0', 0);
            $server->set(['service_name' => 'invalid.service', 'service_resolver' => new Redis(REDIS_SERVER_URL)]);
            try {
                $server->start();
                self::fail('Wildcard address was silently advertised');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('service_address', $e->getMessage());
            }
        });
    }

    public function testWildcardBindRequiresExplicitAdvertisedAddress(): void
    {
        self::coRun(function () {
            $server   = new Server('0.0.0.0', 0);
            $settings = ['service_name' => 'test', 'service_resolver' => new Redis(REDIS_SERVER_URL)];
            try {
                ServiceRegistration::configuration($server, $settings);
                self::fail('An unspecified address must not be advertised');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('service_address', $e->getMessage());
            }
            $config = ServiceRegistration::configuration($server, $settings + ['service_address' => '127.0.0.1']);
            self::assertSame('127.0.0.1', $config[2]);
            self::assertSame($server->port, $config[3]);
        });
    }
}
