<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine\Http2;

use Swoole\Coroutine\Channel;
use Swoole\Http2\Request;
use Swoole\Http2\Response;

use function Swoole\Coroutine\go;

class MultiplexClient extends Client
{
    protected ?Channel $chan = null;

    protected ?Channel $sleepChan = null;

    /**
     * @var array<int, Channel> per-stream channels carrying the responses of in-flight requests
     */
    protected array $streamChannels = [];

    protected bool $idleClose = false;

    protected int $lastSendTime = 0;

    public function request(Request $request, float $timeout = -1): false|Response
    {
        $this->loop();
        $streamId           = $this->send($request);
        $this->lastSendTime = time();

        if ($streamId === false) {
            return false;
        }
        $chan = $this->openStreamChannel($streamId);
        try {
            $data = $chan->pop($timeout);
        } finally {
            $this->closeStreamChannel($streamId);
        }

        return $data;
    }

    public function close(): bool
    {
        $this->flushStreamChannels();
        $this->chan?->close();
        $this->chan = null;
        $this->sleepChan?->close();
        $this->sleepChan = null;
        return parent::close();
    }

    protected function openStreamChannel(int $streamId): Channel
    {
        return $this->streamChannels[$streamId] = new Channel(1);
    }

    protected function closeStreamChannel(int $streamId): void
    {
        if ($channel = $this->streamChannels[$streamId] ?? null) {
            $channel->close();
        }

        unset($this->streamChannels[$streamId]);
    }

    protected function flushStreamChannels(): void
    {
        foreach (array_keys($this->streamChannels) as $streamId) {
            $this->closeStreamChannel($streamId);
        }
    }

    protected function reconnect(): bool
    {
        parent::close();
        return parent::connect();
    }

    protected function loop(): void
    {
        $this->idleClose();

        if ($this->chan !== null) {
            return;
        }
        $this->chan = new Channel(65535);

        if (!$this->ping()) {
            $this->reconnect();
        }
        go(
            function () {
                $reason = '';
                try {
                    $chan = $this->chan;
                    while (true) {
                        $response = $this->recv();

                        if ($chan?->errCode !== SWOOLE_CHANNEL_OK) {
                            $reason = 'channel closed.';
                            break;
                        }

                        if ($response === false) {
                            $reason = 'client broken.';
                            break;
                        }

                        if ($channel = $this->streamChannels[$response->streamId] ?? null) {
                            $channel->push($response);
                        }
                    }
                } catch (\Throwable $exception) {
                    swoole_error_log(SWOOLE_LOG_ERROR, (string) $exception);
                } finally {
                    swoole_error_log(SWOOLE_LOG_DEBUG, 'Recv loop broken, wait to restart in next time. The reason is ' . $reason);
                    $this->close();
                }
            }
        );
    }

    protected function idleClose(): void
    {
        if (!$this->idleClose) {
            $this->idleClose = true;
            go(
                function () {
                    try {
                        while (true) {
                            $this->sleep(3);
                            if ($this->chan === null) {
                                break;
                            }
                            if ($this->streamChannels === [] && time() - $this->lastSendTime > 10) {
                                $this->close();
                                break;
                            }
                        }
                    } finally {
                        $this->idleClose = false;
                    }
                }
            );
        }
    }

    protected function sleep(float $timeout = -1): void
    {
        $this->sleepChan = $this->sleepChan ?? new Channel(1);
        $this->sleepChan->pop($timeout);
    }
}
