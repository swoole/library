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
 * @internal
 * @coversNothing
 */
class ResolverTest extends TestCase
{
    public function testCacheReturnsIndependentClustersAndInvalidates(): void
    {
        $calls    = 0;
        $shared   = self::cluster();
        $resolver = self::resolver(function () use (&$calls, $shared) {
            $calls++;
            return $shared;
        });
        $first = $resolver->lookup('test');
        $first->pop();
        $first->pop();
        self::assertSame(2, $resolver->lookup('test')->count());
        self::assertSame(2, $shared->count());
        self::assertSame(1, $calls);
        $resolver->leave('test', '127.0.0.1', 80);
        self::assertSame(2, $resolver->lookup('test')->count());
        self::assertSame(2, $calls);
    }

    public function testFilterSkipsAndEmptyClusterStops(): void
    {
        $calls    = 0;
        $resolver = self::resolver(function () use (&$calls) {
            $calls++;
            return new Cluster();
        });
        $resolver->withFilter(fn ($name) => str_ends_with($name, '.service'));
        self::assertNull($resolver->lookup('localhost'));
        self::assertSame('', $resolver->lookup('a.service'));
        self::assertSame('', $resolver->lookup('a.service'));
        self::assertSame(1, $calls);
    }

    public function testCacheCapacityAndDisabledCache(): void
    {
        $calls    = 0;
        $resolver = self::resolver(function () use (&$calls) {
            $calls++;
            return self::cluster();
        })->withCache(10, 1, 0);
        $resolver->lookup('a');
        $resolver->lookup('b');
        $resolver->lookup('a');
        self::assertSame(3, $calls);
        $resolver->withCache(0, 1, 0);
        $resolver->lookup('a');
        $resolver->lookup('a');
        self::assertSame(5, $calls);
    }

    public function testExpiredCacheRefreshesAndRecursiveLookupIsRejected(): void
    {
        self::coRun(function () {
            $calls = 0;
            $r     = self::resolver(function () use (&$calls) {
                $calls++;
                return self::cluster();
            })->withCache(0.01);
            $r->lookup('test');
            Coroutine::sleep(0.03);
            $r->lookup('test');
            self::assertSame(2, $calls);
            $recursive = null;
            $recursive = self::resolver(function () use (&$recursive) { return $recursive->lookup('loop'); });
            try {
                $recursive->lookup('loop');
                self::fail('Recursive discovery was allowed');
            } catch (\LogicException $e) {
                self::assertStringContainsString('Recursive lookup', $e->getMessage());
            }
        });
    }

    /** @dataProvider invalidTimeouts */
    public function testInvalidTimeout(float $timeout): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::resolver(fn () => null)->withTimeout($timeout);
    }

    public static function invalidTimeouts(): array
    {
        return [[0], [-1], [INF], [NAN]];
    }

    public function testConcurrentMissesShareOneFetch(): void
    {
        self::coRun(function () {
            $calls    = 0;
            $resolver = self::resolver(function () use (&$calls) {
                $calls++;
                Coroutine::sleep(0.02);
                return self::cluster();
            });
            $results = new Channel(10);
            for ($i = 0; $i < 10; $i++) {
                Coroutine::create(fn () => $results->push($resolver->lookup('test')));
            }
            for ($i = 0; $i < 10; $i++) {
                $cluster = $results->pop(1);
                self::assertSame(2, $cluster->count());
                $cluster->pop();
            }
            self::assertSame(1, $calls);
        });
    }

    public function testConcurrentErrorsArePropagatedAndNotCached(): void
    {
        self::coRun(function () {
            $calls    = 0;
            $resolver = self::resolver(function () use (&$calls) {
                $calls++;
                Coroutine::sleep(0.01);
                throw new Exception('offline');
            });
            $results = new Channel(2);
            $lookup  = function () use ($resolver, $results) {
                try {
                    $resolver->lookup('test');
                } catch (Exception $e) {
                    $results->push($e->getMessage());
                }
            };
            Coroutine::create($lookup);
            Coroutine::create($lookup);
            self::assertSame('offline', $results->pop(1));
            self::assertSame('offline', $results->pop(1));
            self::assertSame(1, $calls);
            Coroutine::create($lookup);
            self::assertSame('offline', $results->pop(1));
            self::assertSame(2, $calls);
        });
    }

    public function testInvalidationDuringFetchDoesNotRepopulateCache(): void
    {
        self::coRun(function () {
            $calls    = 0;
            $started  = new Channel(1);
            $release  = new Channel(1);
            $done     = new Channel(1);
            $resolver = self::resolver(function () use (&$calls, $started, $release) {
                if (++$calls === 1) {
                    $started->push(true);
                    $release->pop(1);
                }
                return self::cluster();
            });
            Coroutine::create(fn () => $done->push($resolver->lookup('test')));
            $started->pop(1);
            $resolver->clearCache('test');
            self::assertSame(2, $resolver->lookup('test')->count());
            $release->push(true);
            $done->pop(1);
            $resolver->lookup('test');
            self::assertSame(2, $calls);
        });
    }

    /** @dataProvider invalidUrls */
    public function testInvalidUrl(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Consul($url);
    }

    public static function invalidUrls(): array
    {
        return [[''], ['localhost:8500'], ['ftp://127.0.0.1'], ['http://127.0.0.1:0'], ['http://127.0.0.1?token=x']];
    }

    private static function resolver(callable $fetch): NameResolver
    {
        return (new class($fetch) extends NameResolver {
            private $fetch;

            public function __construct(callable $fetch)
            {
                parent::__construct('http://127.0.0.1');
                $this->fetch = $fetch;
            }

            public function join(string $name, string $ip, int $port, array $options = []): bool
            {
                $this->clearCache($name);
                return true;
            }

            public function leave(string $name, string $ip, int $port): bool
            {
                $this->clearCache($name);
                return true;
            }

            public function getCluster(string $name): ?Cluster
            {
                return ($this->fetch)($name);
            }
        })->withFilter(fn () => true);
    }

    private static function cluster(): Cluster
    {
        $cluster = new Cluster();
        $cluster->add('127.0.0.1', 80);
        $cluster->add('127.0.0.2', 80);
        return $cluster;
    }
}
