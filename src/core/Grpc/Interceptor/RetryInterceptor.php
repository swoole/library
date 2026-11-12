<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc\Interceptor;

use Swoole\Coroutine;
use Swoole\Grpc\CallOptions;
use Swoole\Grpc\Internal\Backoff;
use Swoole\Grpc\Result;
use Swoole\Grpc\Status\StatusCode;

/**
 * A transparent retry interceptor for unary calls, modeled on etcd clientv3's retry interceptor
 * and grpc-go's service config retry policy. Only transient status codes are retried; callers
 * are responsible for idempotency. Streaming calls are never replayed automatically.
 */
final class RetryInterceptor implements Interceptor
{
    private int $maxAttempts;

    private float $initialBackoff;

    /** @var callable(float): void */
    private $sleeper;

    /**
     * @param callable(float): void|null $sleeper receives a delay in seconds; injectable for tests
     */
    public function __construct(int $maxAttempts = 3, float $initialBackoff = 0.1, ?callable $sleeper = null)
    {
        $this->maxAttempts    = max(1, $maxAttempts);
        $this->initialBackoff = max(0.0, $initialBackoff);
        $this->sleeper        = $sleeper ?? static function (float $seconds): void {
            if ($seconds <= 0) {
                return;
            }
            if (Coroutine::getCid() > 0) {
                Coroutine::sleep($seconds);
            } else {
                usleep((int) ($seconds * 1_000_000));
            }
        };
    }

    public function intercept(CallContext $context, callable $next)
    {
        // Only unary results are safe to replay; streaming calls return a Call object.
        if ($context->getType() !== CallContext::UNARY) {
            return $next($context);
        }

        // A negative timeout (e.g. -1) means "wait forever", not a deadline already in the past.
        $timeout  = CallOptions::parse($context->getMetadata(), $context->getOptions())->getTimeout();
        $deadline = ($timeout === null || $timeout < 0) ? null : microtime(true) + $timeout;
        $backoff  = new Backoff($this->initialBackoff, 2.0, max($this->initialBackoff, 5.0));
        $attempt  = 0;
        while (true) {
            $attempt++;
            $result = $next($context);

            if (!$result instanceof Result || !$result->getStatus()->isRetryable() || $attempt >= $this->maxAttempts) {
                return $result;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                return new Result(null, StatusCode::DEADLINE_EXCEEDED, null);
            }

            $delay = $backoff->delayFor($attempt - 1);
            if ($deadline !== null) {
                $delay = min($delay, max(0.0, $deadline - microtime(true)));
            }
            ($this->sleeper)($delay);
        }
    }
}
