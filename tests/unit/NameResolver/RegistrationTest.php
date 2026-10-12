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
use Swoole\Coroutine\Channel;
use Swoole\NameResolver;
use Swoole\Tests\TestCase;

/**
 * @internal @coversNothing
 * @coversNothing
 */
class RegistrationTest extends TestCase
{
    public function testStopWaitsForHeartbeatAndLeavesOnlyOnce(): void
    {
        self::coRun(function () {
            $state    = (object) ['joins' => 0, 'beats' => 0, 'leaves' => 0, 'events' => [], 'failure' => 0];
            $started  = new Channel(1);
            $release  = new Channel(1);
            $resolver = self::resolver($state, function (int $beat) use ($started, $release): void {
                if ($beat === 2) {
                    $started->push(true);
                    $release->pop(2);
                }
            });
            $registration = $resolver->register('orders', '127.0.0.1', 8080, [], 0.01);
            self::assertTrue($started->pop(2));
            $stopped = new Channel(1);
            Coroutine::create(fn () => $stopped->push($registration->stop()));
            Coroutine::sleep(0.01);
            self::assertSame(0, $state->leaves);
            self::assertFalse($registration->isRunning());
            $release->push(true);
            self::assertTrue($stopped->pop(2));
            self::assertTrue($registration->stop());
            Coroutine::sleep(0.03);
            self::assertSame(['join', 'beat', 'beat', 'leave'], $state->events);
            self::assertNotNull($registration->getLastHeartbeat());
        });
    }

    public function testMissingRegistrationIsRecreatedAndObserversCanStop(): void
    {
        self::coRun(function () {
            $state          = (object) ['joins' => 0, 'beats' => 0, 'leaves' => 0, 'events' => [], 'failure' => 0];
            $resolver       = self::resolver($state);
            $registration   = $resolver->register('orders', '127.0.0.1', 8080, [], 0.01);
            $state->failure = 404;
            $deadline       = microtime(true) + 2;
            while ($state->joins < 2 && microtime(true) < $deadline) {
                Coroutine::sleep(0.002);
            }
            self::assertSame(2, $state->joins);
            self::assertNull($registration->getLastError());
            $observed = new Channel(1);
            $registration->onError(function ($error) use ($registration, $observed) {
                $registration->stop();
                $observed->push($error->getCode());
            });
            $state->failure = 503;
            self::assertSame(503, $observed->pop(2));
            self::assertSame(2, $state->joins);
            self::assertSame(1, $state->leaves);
            self::assertFalse($registration->isRunning());
        });
    }

    public function testUnscopedResolverPreservesDnsFallback(): void
    {
        $state    = (object) ['joins' => 0, 'beats' => 0, 'leaves' => 0, 'events' => [], 'failure' => 0];
        $resolver = self::resolver($state);
        self::assertNull($resolver->lookup('localhost'));
        $resolver->withFilter(fn ($name) => str_ends_with($name, '.service'));
        self::assertNull($resolver->lookup('localhost'));
        self::assertSame('', $resolver->lookup('missing.service'));
    }

    public function testFailedInitialHeartbeatIsDeregistered(): void
    {
        self::coRun(function () {
            $state = (object) ['joins' => 0, 'beats' => 0, 'leaves' => 0, 'events' => [], 'failure' => 503];
            try {
                self::resolver($state)->register('orders', '127.0.0.1', 8080);
                self::fail('Initial heartbeat failure was ignored');
            } catch (Exception $e) {
                self::assertSame(503, $e->getCode());
            }
            self::assertSame(1, $state->leaves);
        });
    }

    public function testRegistrationCannotBeClonedOrSerialized(): void
    {
        self::coRun(function () {
            $state        = (object) ['joins' => 0, 'beats' => 0, 'leaves' => 0, 'events' => [], 'failure' => 0];
            $registration = self::resolver($state)->register('orders', '127.0.0.1', 8080);
            try {
                foreach ([fn () => clone $registration, fn () => serialize($registration)] as $operation) {
                    try {
                        $operation();
                        self::fail('Registration ownership was duplicated');
                    } catch (\LogicException $e) {
                        self::assertStringContainsString('cannot', $e->getMessage());
                    }
                }
            } finally {
                $registration->stop();
            }
        });
    }

    private static function resolver(object $state, ?callable $onBeat = null): NameResolver
    {
        return new class($state, $onBeat) extends NameResolver {
            public function __construct(private object $state, private $onBeat)
            {
                parent::__construct('http://127.0.0.1');
            }

            public function join(string $name, string $ip, int $port, array $options = []): bool
            {
                $this->state->joins++;
                $this->state->events[] = 'join';
                return true;
            }

            public function leave(string $name, string $ip, int $port): bool
            {
                $this->state->leaves++;
                $this->state->events[] = 'leave';
                return true;
            }

            public function getCluster(string $name): ?Cluster
            {
                return null;
            }

            public function heartbeat(string $name, string $ip, int $port, array $options = []): bool
            {
                $this->state->beats++;
                $this->state->events[] = 'beat';
                if ($this->state->failure) {
                    $code                 = $this->state->failure;
                    $this->state->failure = 0;
                    throw new Exception('Registry failure', $code);
                }
                if ($this->onBeat !== null) {
                    ($this->onBeat)($this->state->beats);
                }
                return true;
            }
        };
    }
}
