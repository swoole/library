<?php

declare(strict_types=1);

namespace Swoole\Coroutine\Http2;

use Swoole\Coroutine\Channel;
use Swoole\Http2\Request;
use Swoole\Http2\Response;
use Throwable;

use function Swoole\Coroutine\go;

class Client2 extends Client
{
    protected ?Channel $chan = null;

    protected ?Channel $sleepChan = null;

    protected ChannelManager $channelManager;

    protected bool $idleClose = false;

    protected int $lastSendTime = 0;

    public function __construct(string $host, int $port = 80, bool $open_ssl = false)
    {
        parent::__construct($host, $port, $open_ssl);
        $this->channelManager = new ChannelManager();
    }

    public function request(Request $request, float $timeout = -1): false|Response
    {
        $this->loop();
        $streamId = $this->send($request);
        $this->lastSendTime = time();

        if ($streamId === false) {
            $this->close();
            return false;
        }
        $manager = $this->getChannelManager();
        $chan = $manager->get($streamId, true);
        try {
            $data = $chan->pop($timeout);
        } finally {
            $manager->close($streamId);
        }

        return $data;
    }

    public function close(): bool
    {
        $this->getChannelManager()->flush();
        $this->chan?->close();
        $this->chan = null;
        $this->sleepChan?->close();
        $this->sleepChan = null;
        return parent::close();
    }

    protected function getChannelManager(): ChannelManager
    {
        return $this->channelManager;
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

        if (! $this->ping()) {
            $this->reconnect();
        }
        go(
            function () {
                $reason = '';
                try {
                    $chan = $this->chan;
                    while (true) {
                        $response = $this->recv($this->setting['timeout'] ?? 60);

                        if ($chan->errCode !== SWOOLE_CHANNEL_OK) {
                            $reason = 'channel closed.';
                            break;
                        }

                        if ($response === false) {
                            $reason = 'client broken.';
                            break;
                        }

                        if ($channel = $this->getChannelManager()->get($response->streamId)) {
                            $channel->push($response);
                        }
                    }
                } catch (Throwable $exception) {
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
        if (! $this->idleClose) {
            $this->idleClose = true;
            go(
                function () {
                    try {
                        while (true) {
                            $this->sleep(3);
                            if ($this->chan === null) {
                                break;
                            }
                            if ($this->channelManager->isEmpty() && time() - $this->lastSendTime > 10) {
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
        $this->sleepChan ??= new Channel(1);
        $this->sleepChan->pop($timeout);
    }
}
