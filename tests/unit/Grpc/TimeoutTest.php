<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Grpc;

use Swoole\Grpc\Internal\Timeout;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Internal\Timeout
 */
class TimeoutTest extends TestCase
{
    public function testEncodeUsesLargestExactUnit(): void
    {
        $this->assertSame('5S', Timeout::encode(5.0));
        $this->assertSame('1500m', Timeout::encode(1.5));
        $this->assertSame('2H', Timeout::encode(7200.0));
        $this->assertSame('3M', Timeout::encode(180.0));
        $this->assertSame('250u', Timeout::encode(0.00025));
    }

    public function testEncodeZeroAndNegative(): void
    {
        $this->assertSame('0n', Timeout::encode(0.0));
    }

    public function testEncodeNegativeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timeout::encode(-1.0);
    }

    public function testDecode(): void
    {
        $this->assertSame(5.0, Timeout::decode('5S'));
        $this->assertEqualsWithDelta(1.5, Timeout::decode('1500m'), 1e-9);
        $this->assertEqualsWithDelta(7200.0, Timeout::decode('2H'), 1e-6);
        $this->assertEqualsWithDelta(0.0001, Timeout::decode('100u'), 1e-12);
    }

    public function testDecodeInvalid(): void
    {
        $this->assertNull(Timeout::decode(''));
        $this->assertNull(Timeout::decode('5X'));
        $this->assertNull(Timeout::decode('abc'));
        $this->assertNull(Timeout::decode('S'));
    }

    public function testRoundTrip(): void
    {
        foreach ([0.001, 0.25, 1.0, 1.5, 30.0, 90.0, 3600.0] as $seconds) {
            $decoded = Timeout::decode(Timeout::encode($seconds));
            $this->assertNotNull($decoded, "failed for {$seconds}");
            $this->assertEqualsWithDelta($seconds, $decoded, $seconds * 1e-6 + 1e-9, "roundtrip for {$seconds}");
        }
    }
}
