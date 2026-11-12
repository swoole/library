<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Grpc\Status;

use Swoole\Grpc\Status\StatusCode;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Status\StatusCode
 */
class StatusCodeTest extends TestCase
{
    public function testCanonicalValues(): void
    {
        $this->assertSame(0, StatusCode::OK);
        $this->assertSame(1, StatusCode::CANCELLED);
        $this->assertSame(4, StatusCode::DEADLINE_EXCEEDED);
        $this->assertSame(5, StatusCode::NOT_FOUND);
        $this->assertSame(12, StatusCode::UNIMPLEMENTED);
        $this->assertSame(13, StatusCode::INTERNAL);
        $this->assertSame(14, StatusCode::UNAVAILABLE);
        $this->assertSame(16, StatusCode::UNAUTHENTICATED);
    }

    public function testName(): void
    {
        $this->assertSame('OK', StatusCode::name(StatusCode::OK));
        $this->assertSame('NOT_FOUND', StatusCode::name(StatusCode::NOT_FOUND));
        $this->assertSame('UNAUTHENTICATED', StatusCode::name(StatusCode::UNAUTHENTICATED));
        $this->assertSame('UNKNOWN_CODE_99', StatusCode::name(99));
    }

    public function testIsRetryable(): void
    {
        $this->assertTrue(StatusCode::isRetryable(StatusCode::UNAVAILABLE));
        $this->assertTrue(StatusCode::isRetryable(StatusCode::RESOURCE_EXHAUSTED));
        $this->assertTrue(StatusCode::isRetryable(StatusCode::DEADLINE_EXCEEDED));
        $this->assertFalse(StatusCode::isRetryable(StatusCode::OK));
        $this->assertFalse(StatusCode::isRetryable(StatusCode::INVALID_ARGUMENT));
        $this->assertFalse(StatusCode::isRetryable(StatusCode::NOT_FOUND));
    }
}
