<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc\Status;

/**
 * An RPC that completed with a non-OK gRPC status. Carries the full Status (code, message and
 * trailing metadata) so callers can inspect it, mirroring grpc-go's status.Error / Java's
 * StatusRuntimeException.
 */
class RpcException extends \RuntimeException
{
    private Status $status;

    public function __construct(Status $status)
    {
        $this->status = $status;
        parent::__construct(
            sprintf('gRPC %s (%d): %s', $status->getName(), $status->getCode(), $status->getMessage()),
            $status->getCode()
        );
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function getStatusCode(): int
    {
        return $this->status->getCode();
    }
}
