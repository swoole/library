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

/**
 * An ordered chain of interceptors. Interceptors are applied in registration order; the first
 * one is the outermost. An empty chain simply calls the terminal invoker.
 */
final class Chain
{
    /** @var array<int, Interceptor> */
    private array $interceptors;

    /**
     * @param array<int, Interceptor> $interceptors
     */
    public function __construct(array $interceptors = [])
    {
        $this->interceptors = array_values($interceptors);
    }

    /**
     * @param callable(CallContext): mixed $invoker
     * @return mixed
     */
    public function __invoke(CallContext $context, callable $invoker)
    {
        // Build from the inside out so registration order == execution order (outermost first).
        $next = $invoker;
        foreach (array_reverse($this->interceptors) as $interceptor) {
            $inner = $next;
            $next  = static fn (CallContext $ctx) => $interceptor->intercept($ctx, $inner);
        }

        return $next($context);
    }

    public function isEmpty(): bool
    {
        return $this->interceptors === [];
    }
}
