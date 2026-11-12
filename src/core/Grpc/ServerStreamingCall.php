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

/**
 * An active call that sends one request and receives a stream of responses.
 */
class ServerStreamingCall extends StreamingCall
{
    /**
     * @param object $message
     */
    public function push($message): bool
    {
        throw new \BadMethodCallException('ServerStreamingCall can not push data by client');
    }
}
