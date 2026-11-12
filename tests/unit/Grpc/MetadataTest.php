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

use Swoole\Grpc\Metadata;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Metadata
 */
class MetadataTest extends TestCase
{
    public function testLowercasesKeys(): void
    {
        $this->assertSame(['x-token' => 'abc'], Metadata::normalize(['X-Token' => 'abc']));
    }

    public function testJoinsValues(): void
    {
        $this->assertSame(['x-multi' => 'a,b'], Metadata::normalize(['x-multi' => ['a', 'b']]));
    }

    public function testStripsIllegalAsciiBytes(): void
    {
        $this->assertSame(['x-name' => 'abc'], Metadata::normalize(['x-name' => "a\x00b\nc"]));
    }

    public function testBinaryKeysAreBase64Encoded(): void
    {
        $normalized = Metadata::normalize(['trace-bin' => "\x01\x02\xff"]);
        $this->assertSame('AQL/', $normalized['trace-bin']);
    }

    public function testBinaryRoundTrip(): void
    {
        $raw        = "\x00\x01\xfe hello";
        $normalized = Metadata::normalize(['data-bin' => $raw]);
        $decoded    = Metadata::decodeBinary('data-bin', $normalized['data-bin']);
        $this->assertSame([$raw], $decoded);
    }

    public function testDecodeBinaryNonBinKey(): void
    {
        $this->assertSame(['plain'], Metadata::decodeBinary('x-key', 'plain'));
    }

    public function testReservedGrpcPrefixIsRejected(): void
    {
        // Reserved grpc-* keys are dropped; only the ordinary key survives.
        $this->assertSame(
            ['x-ok' => 'v'],
            Metadata::normalize(['grpc-timeout' => '1S', 'x-ok' => 'v', 'grpc-encoding' => 'gzip'])
        );
    }

    public function testInvalidKeysAreSkipped(): void
    {
        $this->assertSame([], Metadata::normalize(['bad key!' => 'v', '' => 'v']));
    }
}
