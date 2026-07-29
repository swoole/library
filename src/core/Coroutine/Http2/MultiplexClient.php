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
 * A single recv-loop coroutine reads every response off the connection and routes it to the
 * requesting coroutine by stream ID. The connection is health-checked before each request and
 * re-established transparently when it is gone; a heartbeat checker closes it after a period
 * without requests.
 *
 * Besides the settings understood by the underlying client, set() accepts:
 * - heartbeat_check_interval: seconds between two idle checks (default: 3)
 * - heartbeat_idle_time: seconds without a new request after which a connection with no in-flight
 *   requests is closed automatically (default: 10); a non-positive value disables idle closing, in
 *   which case the connection stays open until close() is called or the peer closes it
 */
class MultiplexClient extends Client
{
    /**
     * @var object|null identity token of the currently running recv loop; null when none is running.
     *                  A recv loop whose token no longer matches is stale and must exit without
     *                  closing the client: a newer loop may own the (reconnected) connection already.
     */
    protected ?object $recvLoopToken = null;

    /**
     * @var Channel|null used by the heartbeat checker for an interruptible sleep; closed by close()
     *                   to wake the checker up immediately
     */
    protected ?Channel $sleepChannel = null;

    /**
     * @var Channel|null present while a coroutine health-checks the connection and starts the shared
     *                   recv loop; other coroutines block on it (until it is closed) before sending
     */
    protected ?Channel $startupBarrier = null;

    /**
     * @var array<int, Channel> per-stream channels carrying the responses of in-flight requests
     */
    protected array $streamChannels = [];

    /**
     * @var array<int, true> IDs of streams whose requester timed out; the recv loop drops their late
     *                       responses instead of parking them
     */
    protected array $abandonedStreams = [];

    /**
     * @var bool guards against spawning more than one heartbeat-checker coroutine
     */
    protected bool $heartbeatCheckerRunning = false;

    /**
     * @var float Unix timestamp of the most recent activity (connection startup or send); the
     *            heartbeat checker measures idleness against it
     */
    protected float $lastActiveTime = 0;

    /**
     * Sends a request over the shared connection and waits for its response.
     *
     * Safe to call from any number of coroutines concurrently; the connection is established (or
     * re-established) on demand.
     *
     * @param float $timeout seconds to wait for the response. Non-positive values fall back to the
     *                       client's `timeout` setting; without that setting the wait is unbounded.
     * @return false|Response the response, or false when sending failed, the wait timed out, or the
     *                        connection was closed while waiting
     */
    public function request(Request $request, float $timeout = -1): false|Response
    {
        if ($timeout <= 0) {
            // The ping check in ensureRecvLoop() only confirms the request could be written out, not
            // that the peer is alive, so an unbounded default wait could hang on a stale connection
            // forever.
            $timeout = (float) ($this->setting['timeout'] ?? -1);
        }
        $this->ensureRecvLoop();
        // Stamped on both sides of send(): before, so that a send in progress never counts as idle
        // time (send() can yield); after, so that idleness is measured from send completion.
        $this->lastActiveTime = microtime(true);
        $streamId             = $this->send($request);
        $this->lastActiveTime = microtime(true);

        if ($streamId === false) {
            return false;
        }
        // The response can arrive before send() returns when send() yields on socket I/O; the recv
        // loop then has registered the stream channel already, with the response buffered in it.
        $chan = $this->streamChannels[$streamId] ?? null;
        if ($chan === null) {
            if ($this->recvLoopToken === null) {
                // The client was closed while send() yielded, and the flush has already dropped any
                // response parked for this stream: fail fast instead of waiting out the timeout on
                // a channel nothing will ever push to.
                return false;
            }
            $chan = $this->openStreamChannel($streamId);
        }
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

    /**
     * Closes the connection, aborting every in-flight request (their request() calls return false)
     * and stopping the recv loop and the heartbeat checker.
     *
     * The client remains usable: the next request() reconnects.
     */
    public function close(): bool
    {
        // Invalidate the connection before waking anyone: the recv loop keys off the cleared token
        // (so a stale loop cannot tear down a successor connection), and a requester woken by the
        // flush below may retry immediately — it must find the connection closed and reconnect
        // instead of sending into the socket being torn down.
        $this->recvLoopToken = null;
        $result              = parent::close();
        $this->flushStreamChannels();
        $this->abandonedStreams = [];
        $this->sleepChannel?->close();
        $this->sleepChannel = null;
        return $result;
    }

    /**
     * Registers the channel over which the response for the given stream will be delivered.
     */
    protected function openStreamChannel(int $streamId): Channel
    {
        return $this->streamChannels[$streamId] = new Channel(1);
    }

    /**
     * Unregisters a stream's channel, waking its requester (pop() returns false) if one still waits.
     */
    protected function closeStreamChannel(int $streamId): void
    {
        if ($channel = $this->streamChannels[$streamId] ?? null) {
            $channel->close();
        }

        unset($this->streamChannels[$streamId]);
    }

    /**
     * Aborts all in-flight requests by closing and unregistering their stream channels.
     */
    protected function flushStreamChannels(): void
    {
        foreach (array_keys($this->streamChannels) as $streamId) {
            $this->closeStreamChannel($streamId);
        }
    }

    /**
     * Re-establishes the connection, discarding the current one.
     */
    protected function reconnect(): bool
    {
        parent::close();
        return parent::connect();
    }

    /**
     * Makes sure the connection is healthy and the shared recv loop is running, (re)connecting and
     * starting the loop when needed. Serializes concurrent callers so that none of them sends while
     * the connection is still being set up.
     */
    protected function ensureRecvLoop(): void
    {
        $this->ensureHeartbeatChecker();

        // The health check and the reconnect below both yield; sending during either would fail
        // spuriously. Coroutines arriving in the meantime must wait for the starter to finish
        // (closing the channel wakes all waiters) and then re-check, as the state has changed.
        while ($this->startupBarrier !== null) {
            $this->startupBarrier->pop();
        }
        if ($this->recvLoopToken !== null) {
            return;
        }

        $this->startupBarrier = new Channel(1);
        $spawned              = false;
        $token                = null;
        try {
            $token = $this->recvLoopToken = new \stdClass();
            // The connection is being (re)established: reset the idle clock, or the heartbeat
            // checker could close the connection mid-startup based on a stale (or zero) stamp —
            // no stream channel is registered before send() returns, so nothing else marks this
            // request as in flight yet.
            $this->lastActiveTime = microtime(true);
            if (!$this->ping()) {
                $this->reconnect();
            }
            // close() may have cleared the token while ping()/reconnect() yielded. A loop spawned
            // with that stale token would park in recv() — binding the socket — while looking
            // stopped to everyone else, and the next request would spawn a second reader onto the
            // same socket.
            if ($this->recvLoopToken === $token) {
                go(fn () => $this->recvLoop($token));
                $spawned = true;
            }
        } finally {
            if (!$spawned && $this->recvLoopToken === $token) {
                // Startup died before the recv loop was spawned (an exception from the health
                // check or reconnect): leave no token behind, or every later request would skip
                // reconnection and send with no coroutine reading the connection.
                $this->recvLoopToken = null;
            }
            // Clear the property before closing: close() resumes the waiters immediately, and a
            // waiter that still saw the barrier set would re-enter pop() on the closed channel,
            // which returns synchronously — a busy spin that never yields back to this coroutine.
            $barrier              = $this->startupBarrier;
            $this->startupBarrier = null;
            $barrier->close();
        }
    }

    /**
     * Reads responses off the connection and routes each one to its requester's stream channel;
     * runs in a coroutine of its own until the connection breaks or the client is closed.
     *
     * @param object $token the loop's identity: once it no longer matches $recvLoopToken, this loop
     *                      is stale and exits without closing the client
     */
    protected function recvLoop(object $token): void
    {
        $reason = '';
        try {
            while (true) {
                // Wait without a read timeout: recv() called without one falls back to the socket's
                // read timeout (the `timeout` setting, 60 seconds when unset), which would tear down
                // a merely quiet connection as broken — aborting every in-flight request with it —
                // and cap how long any single response may take regardless of the timeout passed to
                // request(). Idle teardown belongs to the heartbeat checker alone.
                $response = $this->recv(-1);

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

    /**
     * Makes sure the heartbeat checker is running, unless disabled: a coroutine that closes the
     * connection once no requests are in flight and none was sent for `heartbeat_idle_time` seconds.
     */
    protected function ensureHeartbeatChecker(): void
    {
        if ($this->heartbeatCheckerRunning) {
            return;
        }
        $maxIdleTime = (float) ($this->setting[Constant::OPTION_HEARTBEAT_IDLE_TIME] ?? 10);
        if ($maxIdleTime <= 0) {
            return;
        }
        $checkInterval = (float) ($this->setting[Constant::OPTION_HEARTBEAT_CHECK_INTERVAL] ?? 3);

        $this->heartbeatCheckerRunning = true;
        go(
            function () use ($checkInterval, $maxIdleTime) {
                try {
                    while (true) {
                        $this->interruptibleSleep($checkInterval);
                        if ($this->recvLoopToken === null) {
                            break;
                        }
                        if ($this->streamChannels === [] && microtime(true) - $this->lastActiveTime > $maxIdleTime) {
                            $this->close();
                            break;
                        }
                    }
                } finally {
                    $this->heartbeatCheckerRunning = false;
                }
            }
        );
    }

    /**
     * Sleeps for the given number of seconds, or until close() wakes the sleeper up early by
     * closing the sleep channel.
     */
    protected function interruptibleSleep(float $seconds): void
    {
        $this->sleepChannel = $this->sleepChannel ?? new Channel(1);
        $this->sleepChannel->pop($seconds);
    }
}
