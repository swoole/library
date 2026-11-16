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
use Swoole\NameResolver;
use Swoole\Server;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\NameResolver\ServiceRegistration
 */
class ServiceRegistrationTest extends TestCase
{
    public function testResolverCandidatesMustBeAnArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name_resolver must be an array');
        ServiceRegistration::configuration((object) ['host' => '127.0.0.1', 'port' => 8080], [
            'service_name' => 'test', 'name_resolver' => new \stdClass(),
        ]);
    }

    public function testRepeatedServerStopDeregistersOnce(): void
    {
        self::coRun(static function (): void {
            $state    = (object) ['joins' => 0, 'leaves' => 0];
            $resolver = new class($state) extends NameResolver {
                public function __construct(private object $state)
                {
                    parent::__construct('http://127.0.0.1');
                }

                public function join(string $name, string $ip, int $port, array $options = []): bool
                {
                    $this->state->joins++;
                    return true;
                }

                public function leave(string $name, string $ip, int $port): bool
                {
                    $this->state->leaves++;
                    return true;
                }

                public function getCluster(string $name): ?Cluster
                {
                    return null;
                }

                public function heartbeat(string $name, string $ip, int $port, array $options = []): bool
                {
                    return true;
                }
            };
            $server = new class extends Server {
                public function __construct()
                {
                }
            };
            $server->host    = '127.0.0.1';
            $server->port    = 8080;
            $server->setting = ['service_name' => 'test', 'service_resolver' => $resolver];
            ServiceRegistration::startServer($server);
            ServiceRegistration::stopServer($server);
            ServiceRegistration::stopServer($server);
            ServiceRegistration::stopServer($server);
            Coroutine::sleep(0.001);
            self::assertSame(1, $state->joins);
            self::assertSame(1, $state->leaves);
        });
    }
}
