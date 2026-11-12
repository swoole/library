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
 * A client-side interceptor. Interceptors wrap the invocation and may inspect/modify the
 * CallContext, short-circuit, or post-process the Result (auth, retry, logging, tracing...).
 * Follows the grpc-go UnaryClientInterceptor / grpc-node InterceptingCall model.
 */
interface Interceptor
{
    /**
     * @param callable(CallContext): mixed $next the rest of the chain; the final invoker
     *                                           returns a Result (unary) or a streaming Call.
     * @return mixed
     */
    public function intercept(CallContext $context, callable $next);
}
