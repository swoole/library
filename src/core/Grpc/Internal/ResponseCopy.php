<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc\Internal;

use Swoole\Http2\Response;

/**
 * One HTTP/2 response per gRPC message.
 *
 * Swoole\Http2\Response cannot be cloned. A single chunk can carry several gRPC messages, so each
 * message is parsed from its own response and the caller's buffer stays intact.
 *
 * @internal
 */
final class ResponseCopy
{
    public static function withFrame(Response $response, string $frame): Response
    {
        $copy                     = new Response();
        $copy->streamId           = $response->streamId;
        $copy->errCode            = $response->errCode;
        $copy->statusCode         = $response->statusCode;
        $copy->pipeline           = $response->pipeline;
        $copy->headers            = $response->headers;
        $copy->set_cookie_headers = $response->set_cookie_headers;
        $copy->cookies            = $response->cookies;
        // Added after Swoole 6.2. 6.1 and 6.2 have no such property; writing it would be a dynamic property.
        self::copyIfPresent($response, $copy, 'trailers');
        $copy->data = $frame;

        return $copy;
    }

    /**
     * Copies one public property when both objects actually declare it.
     */
    public static function copyIfPresent(object $from, object $to, string $name): void
    {
        if (!property_exists($from, $name) || !property_exists($to, $name)) {
            return;
        }

        $to->{$name} = $from->{$name};
    }
}
