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

use Swoole\Constant;
use Swoole\Coroutine\Channel;
use Swoole\Http2\Request;
use Swoole\Http2\Response;

use function Swoole\Coroutine\go;

// This file is packed into the Swoole extension and evaluated at startup, where declaring a class
// whose parent is missing is a fatal error; the parent class only exists when the extension is
// compiled with HTTP/2 support (--enable-http2).
if (!class_exists(Client::class, false)) {
    return;
}

/**
 * An HTTP/2 client that multiplexes requests from many coroutines over one shared connection.
 *
 * Besides the settings understood by the underlying client, set() accepts:
 * - heartbeat_check_interval: seconds between two idle checks (default: 3)
 * - heartbeat_idle_time: seconds without a new request after which a connection with no in-flight
 *   requests is closed automatically (default: 10); a non-positive value disables idle closing
 */
class MultiplexClient extends Client
{
    /**
     * @var object|null identity token of the currently running recv loop; null when none is running.
     *                  A recv loop whose token no longer matches is stale and must exit without
     *                  closing the client: a newer loop may own the (reconnected) connection already.
     */
    protected ?object $recvLoopToken = null;

    protected ?Channel $sleepChan = null;

    /**
     * @var Channel|null present while a coroutine health-checks the connection and starts the shared
     *                   recv loop; other coroutines block on it (until it is closed) before sending
     */
    protected ?Channel $starting = null;

    /**
     * @var array<int, Channel> per-stream channels carrying the responses of in-flight requests
     */
    protected array $streamChannels = [];

    /**
     * @var array<int, true> IDs of streams whose requester timed out; their late responses are dropped
     */
    protected array $abandonedStreams = [];

    protected bool $idleClose = false;

    protected int $lastSendTime = 0;

    /**
     * Sends a request over the shared connection and waits for its response.
     *
     * @param float $timeout seconds to wait for the response. Non-positive values fall back to the
     *                       client's `timeout` setting; without that setting the wait is unbounded.
     */
    public function request(Request $request, float $timeout = -1): false|Response
    {
        if ($timeout <= 0) {
            // The ping check in loop() only confirms the request could be written out, not that the
            // peer is alive, so an unbounded default wait could hang on a stale connection forever.
            $timeout = (float) ($this->setting['timeout'] ?? -1);
        }
        $this->loop();
        $streamId           = $this->send($request);
        $this->lastSendTime = time();

        if ($streamId === false) {
            return false;
        }
        // The response can arrive before send() returns when send() yields on socket I/O; the recv
        // loop then has registered the stream channel already, with the response buffered in it.
        $chan = $this->streamChannels[$streamId] ?? $this->openStreamChannel($streamId);
        $data = false;
        try {
            $data = $chan->pop($timeout);
        } finally {
            $this->closeStreamChannel($streamId);
            if ($data === false && $chan->errCode === SWOOLE_CHANNEL_TIMEOUT) {
                // Nobody waits for this stream anymore; the recv loop must drop its late response
                // instead of parking it, which would leak the parked channel until the connection
                // closes. On connection teardown (SWOOLE_CHANNEL_CLOSED) no marker may be left:
                // stream IDs restart after a reconnect, and a stale marker would drop a response
                // of the next connection.
                $this->abandonedStreams[$streamId] = true;
            }
        }

        return $data;
    }

    public function close(): bool
    {
        $this->flushStreamChannels();
        $this->abandonedStreams = [];
        $this->recvLoopToken    = null;
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

        // The health check and the reconnect below both yield; sending during either would fail
        // spuriously. Coroutines arriving in the meantime must wait for the starter to finish
        // (closing the channel wakes all waiters) and then re-check, as the state has changed.
        while ($this->starting !== null) {
            $this->starting->pop();
        }
        if ($this->recvLoopToken !== null) {
            return;
        }

        $this->starting = new Channel(1);
        try {
            $token = $this->recvLoopToken = new \stdClass();
            if (!$this->ping()) {
                $this->reconnect();
            }
            go(fn () => $this->recvLoop($token));
        } finally {
            $this->starting->close();
            $this->starting = null;
        }
    }

    protected function recvLoop(object $token): void
    {
        $reason = '';
        try {
            while (true) {
                $response = $this->recv();

                if ($this->recvLoopToken !== $token) {
                    $reason = 'client closed.';
                    break;
                }

                if ($response === false) {
                    $reason = 'connection broken.';
                    break;
                }

                $streamId = $response->streamId;
                if ($channel = $this->streamChannels[$streamId] ?? null) {
                    $channel->push($response);
                } elseif (isset($this->abandonedStreams[$streamId])) {
                    unset($this->abandonedStreams[$streamId]);
                } elseif ($streamId % 2 === 1) {
                    // The response won the race against its requester registering the stream
                    // channel (client-initiated streams have odd IDs): register the channel
                    // here and buffer the response for the requester to pick up.
                    $this->openStreamChannel($streamId)->push($response);
                }
            }
        } catch (\Throwable $exception) {
            swoole_error_log(SWOOLE_LOG_ERROR, (string) $exception);
        } finally {
            swoole_error_log(SWOOLE_LOG_DEBUG, 'Recv loop broken, wait to restart in next time. The reason is ' . $reason);
            if ($this->recvLoopToken === $token) {
                $this->close();
            }
        }
    }

    protected function idleClose(): void
    {
        if ($this->idleClose) {
            return;
        }
        $maxIdleTime = (float) ($this->setting[Constant::OPTION_HEARTBEAT_IDLE_TIME] ?? 10);
        if ($maxIdleTime <= 0) {
            return;
        }
        $checkInterval = (float) ($this->setting[Constant::OPTION_HEARTBEAT_CHECK_INTERVAL] ?? 3);

        $this->idleClose = true;
        go(
            function () use ($checkInterval, $maxIdleTime) {
                try {
                    while (true) {
                        $this->sleep($checkInterval);
                        if ($this->recvLoopToken === null) {
                            break;
                        }
                        if ($this->streamChannels === [] && time() - $this->lastSendTime > $maxIdleTime) {
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

    protected function sleep(float $timeout = -1): void
    {
        $this->sleepChan = $this->sleepChan ?? new Channel(1);
        $this->sleepChan->pop($timeout);
    }
}
