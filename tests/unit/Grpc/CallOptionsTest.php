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

use Swoole\Grpc\CallOptions;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\CallOptions
 */
class CallOptionsTest extends TestCase
{
    public function testDefaultsProduceNoNewHeaders(): void
    {
        $options = CallOptions::parse([], []);
        $this->assertSame([], $options->buildHeaders());
        $this->assertNull($options->getTimeout());
        $this->assertSame('identity', $options->getCompression());
        $this->assertFalse($options->shouldThrowOnError());
        $this->assertFalse($options->shouldWaitForReady());
    }

    public function testLegacyHeadersStillHonoured(): void
    {
        // Existing behaviour: $options['headers'] must keep working.
        $options = CallOptions::parse([], ['headers' => ['X-Custom' => 'v']]);
        $this->assertSame(['x-custom' => 'v'], $options->buildHeaders());
    }

    public function testMetadataIsNormalized(): void
    {
        $options = CallOptions::parse(['X-Token' => 'abc'], []);
        $this->assertSame(['x-token' => 'abc'], $options->buildHeaders());
    }

    public function testOptionsMetadataMerged(): void
    {
        $options = CallOptions::parse([], ['metadata' => ['x-opt' => '1']]);
        $this->assertSame(['x-opt' => '1'], $options->buildHeaders());
        $this->assertSame(['x-opt' => '1'], $options->getMetadata());
    }

    public function testTimeoutEncodesGrpcTimeoutHeader(): void
    {
        $options = CallOptions::parse([], ['timeout' => 1.5]);
        $this->assertSame(1.5, $options->getTimeout());
        $this->assertSame('1500m', $options->buildHeaders()['grpc-timeout']);
    }

    public function testDeadlineAsDuration(): void
    {
        $options = CallOptions::parse([], ['deadline' => 2.0]);
        $this->assertSame(2.0, $options->getTimeout());
    }

    public function testCompressionHeaders(): void
    {
        $options = CallOptions::parse([], ['compression' => 'gzip']);
        $headers = $options->buildHeaders();
        $this->assertSame('gzip', $headers['grpc-encoding']);
        $this->assertSame('gzip', $headers['grpc-accept-encoding']);
        $this->assertSame('gzip', $options->getCompression());
    }

    public function testIdentityCompressionAddsNoHeader(): void
    {
        $options = CallOptions::parse([], ['compression' => 'identity']);
        $this->assertArrayNotHasKey('grpc-encoding', $options->buildHeaders());
    }

    public function testThrowOnErrorFlag(): void
    {
        $this->assertTrue(CallOptions::parse([], ['grpc.throw_on_error' => true])->shouldThrowOnError());
        $this->assertFalse(CallOptions::parse([], [])->shouldThrowOnError());
    }

    public function testWaitForReadyFlag(): void
    {
        $this->assertTrue(CallOptions::parse([], ['wait_for_ready' => true])->shouldWaitForReady());
    }
}
