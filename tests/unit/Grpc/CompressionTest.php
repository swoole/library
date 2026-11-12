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

use Swoole\Grpc\Compression\GzipCompressor;
use Swoole\Grpc\Compression\IdentityCompressor;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Compression\GzipCompressor
 * @covers \Swoole\Grpc\Compression\IdentityCompressor
 */
class CompressionTest extends TestCase
{
    public function testIdentity(): void
    {
        $c = new IdentityCompressor();
        $this->assertSame('identity', $c->name());
        $this->assertSame(0, $c->flag());
        $this->assertSame('hello', $c->compress('hello'));
        $this->assertSame('hello', $c->decompress('hello'));
    }

    public function testGzipRoundTrip(): void
    {
        if (!function_exists('gzencode')) {
            $this->markTestSkipped('ext-zlib required');
        }
        $c = new GzipCompressor();
        $this->assertSame('gzip', $c->name());
        $this->assertSame(1, $c->flag());
        $payload    = str_repeat('abc123', 1000);
        $compressed = $c->compress($payload);
        $this->assertNotSame($payload, $compressed);
        $this->assertSame($payload, $c->decompress($compressed));
    }

    public function testGzipDecompressInvalidData(): void
    {
        if (!function_exists('gzencode')) {
            $this->markTestSkipped('ext-zlib required');
        }
        $this->expectException(\RuntimeException::class);
        (new GzipCompressor())->decompress('not-gzip');
    }
}
