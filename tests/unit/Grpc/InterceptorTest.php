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

use Swoole\Grpc\Interceptor\CallContext;
use Swoole\Grpc\Interceptor\Interceptor;
use Swoole\Grpc\Interceptor\RetryInterceptor;
use Swoole\Grpc\Result;
use Swoole\Grpc\Status\StatusCode;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Interceptor\CallContext
 * @covers \Swoole\Grpc\Interceptor\Chain
 * @covers \Swoole\Grpc\Interceptor\RetryInterceptor
 */
class InterceptorTest extends TestCase
{
    public function testEmptyChainCallsInvoker(): void
    {
        $chain = new \Swoole\Grpc\Interceptor\Chain();
        $ctx   = new CallContext('/m');
        $this->assertTrue($chain->isEmpty());
        $this->assertSame('done', $chain($ctx, static fn () => 'done'));
    }

    public function testOrderAndMetadataRewrite(): void
    {
        $order = [];
        $a     = $this->recordingInterceptor('a', $order);
        $b     = $this->recordingInterceptor('b', $order);
        $chain = new \Swoole\Grpc\Interceptor\Chain([$a, $b]);
        $this->assertFalse($chain->isEmpty());

        $ctx    = (new CallContext('/m'))->withMetadata(['x' => '1']);
        $result = $chain($ctx, static fn (CallContext $c) => $c->getMetadata());
        $this->assertSame(['a', 'b'], $order);
        // Each interceptor adds its marker to metadata; innermost sees both.
        $this->assertSame(['x' => '1', 'marker-a' => '1', 'marker-b' => '1'], $result);
    }

    public function testCallContextAccessors(): void
    {
        $ctx = new CallContext('/svc/Method', CallContext::BIDI_STREAMING, ['k' => 'v'], ['o' => '1'], 'arg');
        $this->assertSame('/svc/Method', $ctx->getMethod());
        $this->assertSame(CallContext::BIDI_STREAMING, $ctx->getType());
        $this->assertSame(['k' => 'v'], $ctx->getMetadata());
        $this->assertSame(['o' => '1'], $ctx->getOptions());
        $this->assertSame('arg', $ctx->getArgument());
        $this->assertSame(['z' => '9'], $ctx->withOptions(['z' => '9'])->getOptions());
    }

    public function testRetryInterceptorRetriesTransientUntilSuccess(): void
    {
        $sleeperCalls = 0;
        $sleeper      = static function () use (&$sleeperCalls): void {
            $sleeperCalls++;
        };
        $retry = new RetryInterceptor(3, 0.0, $sleeper);

        $attempt = 0;
        $invoker = static function () use (&$attempt) {
            $attempt++;
            $code = $attempt < 3 ? StatusCode::UNAVAILABLE : StatusCode::OK;
            return new Result(null, $code, null);
        };

        $result = $retry->intercept(new CallContext('/m', CallContext::UNARY), $invoker);
        $this->assertTrue($result->isOk());
        $this->assertSame(3, $attempt);
        $this->assertSame(2, $sleeperCalls);
    }

    public function testRetryInterceptorStopsAtMaxAttempts(): void
    {
        $retry = new RetryInterceptor(2, 0.0, static function (): void {
        });
        $attempt = 0;
        $invoker = static function () use (&$attempt) {
            $attempt++;
            return new Result(null, StatusCode::UNAVAILABLE, null);
        };
        $result = $retry->intercept(new CallContext('/m', CallContext::UNARY), $invoker);
        $this->assertSame(StatusCode::UNAVAILABLE, $result->getStatusCode());
        $this->assertSame(2, $attempt);
    }

    public function testRetryInterceptorDoesNotRetryNonRetryable(): void
    {
        $retry = new RetryInterceptor(3, 0.0, static function (): void {
        });
        $attempt = 0;
        $invoker = static function () use (&$attempt) {
            $attempt++;
            return new Result(null, StatusCode::INVALID_ARGUMENT, null);
        };
        $retry->intercept(new CallContext('/m', CallContext::UNARY), $invoker);
        $this->assertSame(1, $attempt);
    }

    public function testRetryInterceptorSkipsStreaming(): void
    {
        $retry = new RetryInterceptor(3, 0.0, static function (): void {
        });
        $attempt = 0;
        $invoker = static function () use (&$attempt) {
            $attempt++;
            return 'a-call-object';
        };
        $result = $retry->intercept(new CallContext('/m', CallContext::SERVER_STREAMING), $invoker);
        $this->assertSame('a-call-object', $result);
        $this->assertSame(1, $attempt);
    }

    /**
     * @param array<int, string> $order
     */
    private function recordingInterceptor(string $name, array &$order): Interceptor
    {
        return new class($name, $order) implements Interceptor {
            private string $name;

            /** @var array<int, string> */
            private array $order;

            /**
             * @param array<int, string> $order
             */
            public function __construct(string $name, array &$order)
            {
                $this->name  = $name;
                $this->order = &$order;
            }

            public function intercept(CallContext $context, callable $next)
            {
                $this->order[]                    = $this->name;
                $metadata                         = $context->getMetadata();
                $metadata["marker-{$this->name}"] = '1';
                return $next($context->withMetadata($metadata));
            }
        };
    }
}
