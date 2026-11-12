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
 * An active call that streams requests and then receives one response.
 */
class ClientStreamingCall extends StreamingCall
{
    private bool $received = false;

    /**
     * @return array{0: mixed, 1: int, 2: mixed}
     */
    public function recv(float $timeout = Client::DEFAULT_TIMEOUT): array
    {
        if ($this->received) {
            throw new \LogicException('ClientStreamingCall can only recv once');
        }
        $this->received = true;

        return parent::recv($timeout);
    }
}
