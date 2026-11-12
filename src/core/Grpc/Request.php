<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc;

use Swoole\Http2\Request as Http2Request;

/**
 * An HTTP/2 request pre-filled with the headers gRPC requires.
 *
 * The parent property is an untyped property of the Swoole extension, so the defaults are set in
 * the constructor instead of by redeclaring it (a native type on the redeclaration is rejected at
 * runtime even though the IDE stubs describe the parent property as array).
 */
class Request extends Http2Request
{
    public function __construct()
    {
        $this->headers = [
            'content-type' => 'application/grpc',
            'te'           => 'trailers',
            'user-agent'   => 'grpc-swoole',
        ];
    }
}
