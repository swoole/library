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

use Swoole\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ClusterTest extends TestCase
{
    public function testWeightsAndUniqueEndpoints(): void
    {
        $cluster = new Cluster();
        $cluster->add('127.0.0.1', 80, 1);
        $cluster->add('127.0.0.1', 80, 0.5);
        $cluster->add('::1', 80, 10);
        $cluster->add('0:0:0:0:0:0:0:1', 80, 0);
        self::assertSame(1, $cluster->count());
        self::assertSame(['host' => '127.0.0.1', 'port' => 80, 'weight' => 0.5], $cluster->pop());
        self::assertFalse($cluster->pop());
    }

    public function testNodesAreRemovedWithoutReplacement(): void
    {
        $cluster = new Cluster();
        $cluster->add('127.0.0.1', 80, 1);
        $cluster->add('127.0.0.2', 80, 65535);
        $copy   = clone $cluster;
        $first  = $copy->pop();
        $second = $copy->pop();
        self::assertNotSame($first['host'], $second['host']);
        self::assertFalse($copy->pop());
        self::assertSame(2, $cluster->count());
        self::assertCount(2, $cluster->getNodes());
    }

    /** @dataProvider invalidNodes */
    public function testInvalidEndpoints(string $host, int $port, int|float $weight): void
    {
        $this->expectException(Exception::class);
        (new Cluster())->add($host, $port, $weight);
    }

    public static function invalidNodes(): array
    {
        return [['hostname', 80, 1], ['127.0.0.1', 0, 1], ['::1', 65536, 1],
            ['127.0.0.1', 80, -1], ['127.0.0.1', 80, INF], ['127.0.0.1', 80, NAN]];
    }

    public function testWeightedSelection(): void
    {
        $cluster = new Cluster();
        $cluster->add('127.0.0.1', 80, 1);
        $cluster->add('127.0.0.2', 80, 9);
        $heavy = 0;
        for ($i = 0; $i < 2000; $i++) {
            $copy = clone $cluster;
            $heavy += $copy->pop()['host'] === '127.0.0.2' ? 1 : 0;
        }
        // Very wide limits reject uniform selection without relying on an exact random sequence.
        self::assertGreaterThan(1500, $heavy);
        self::assertLessThan(1990, $heavy);
    }
}
