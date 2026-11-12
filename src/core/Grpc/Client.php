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

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http2\Client as Http2Client;
use Swoole\Grpc\Internal\Backoff;
use Swoole\Http2\Response as Http2Response;

/**
 * A multiplexed gRPC client: one HTTP/2 connection, one send loop and one recv loop, with every
 * in-flight stream waiting on its own channel. All requests must be made from coroutines.
 */
class Client
{
    /** Default recv() timeout; -1 waits indefinitely. */
    public const DEFAULT_TIMEOUT = -1.0;

    private const CONTROL_KEYS = [
        'endpoints'                  => true,
        'keepalive_time'             => true,
        'reconnect_attempts'         => true,
        'reconnect_backoff_initial'  => true,
        'reconnect_backoff_max'      => true,
    ];

    /** @var array{constructed_num: int, destructed_num: int} */
    protected static array $numStats = [
        'constructed_num' => 0,
        'destructed_num'  => 0,
    ];

    protected static bool $debug = false;

    // ====== send ======
    /** Requests from all coroutines are proxied through this channel. */
    protected ?Channel $sendChannel = null;

    /** Carries the result of a send()/write() back to its caller. */
    protected ?Channel $sendRetChannel = null;

    /** Serializes push+pop so a caller always receives its own result. */
    protected ?Channel $sendLock = null;

    protected int $sendCid = 0;

    // ===== recv ======
    /** @var array<int, Channel> */
    protected array $recvChannelMap = [];

    protected int $recvCid = 0;

    /** Whether close() was called. */
    protected bool $closed = false;

    protected string $host;

    protected int $port;

    protected bool $ssl;

    /** @var array<string, mixed> */
    protected array $opts;

    /** Options forwarded to the underlying Http2 client (our own control keys removed). */
    protected array $clientOpts;

    /** Ordered candidate endpoints for connection-level failover; the first is the primary host. */
    protected array $endpoints = [];

    protected int $endpointIndex = 0;

    protected float $keepaliveTime = 0.0;

    protected int $reconnectAttempts = 1;

    protected Backoff $backoff;

    protected Http2Client $client;

    protected float $timeout = self::DEFAULT_TIMEOUT;

    public function __construct(string $hostname, array $opts = [])
    {
        $parts = self::parseEndpoint($hostname);
        if ($parts === null) {
            throw new \InvalidArgumentException("The hostname {$hostname} is illegal!");
        }

        $this->host = $parts['host'];
        $this->port = $parts['port'];

        $defaultOpts = [
            'timeout'       => self::DEFAULT_TIMEOUT,
            'ssl'           => false,
            'ssl_host_name' => '',
        ];
        $this->opts        = $opts + $defaultOpts;
        $this->timeout     = (float) $this->opts['timeout'];
        $this->ssl         = (bool) $this->opts['ssl'] || (bool) $this->opts['ssl_host_name'];
        $this->opts['ssl'] = $this->ssl;

        // Connection resilience knobs (all opt-in; defaults reproduce the original behaviour).
        $this->keepaliveTime     = max(0.0, (float) ($this->opts['keepalive_time'] ?? 0));
        $this->reconnectAttempts = max(1, (int) ($this->opts['reconnect_attempts'] ?? 1));
        $this->backoff           = new Backoff(
            (float) ($this->opts['reconnect_backoff_initial'] ?? 0.1),
            2.0,
            (float) ($this->opts['reconnect_backoff_max'] ?? 5.0)
        );

        $this->endpoints[] = $parts;
        $extra             = $this->opts['endpoints'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $endpoint) {
                $parsed = is_string($endpoint) ? self::parseEndpoint($endpoint) : null;
                if ($parsed !== null) {
                    $this->endpoints[] = $parsed;
                }
            }
        }

        // Only transport-level settings reach Http2Client::set().
        $this->clientOpts = array_diff_key($this->opts, self::CONTROL_KEYS);

        $this->client = new Http2Client($this->host, $this->port, $this->ssl);
        $this->client->set($this->clientOpts);
        static::$numStats['constructed_num']++;

        $this->start();
    }

    public function __destruct()
    {
        static::$numStats['destructed_num']++;
    }

    public function __get(string $name)
    {
        return $this->client->{$name} ?? null;
    }

    public static function numStats(): array
    {
        return static::$numStats;
    }

    public static function debug(bool $enable = true): void
    {
        static::$debug = $enable;
    }

    /**
     * @param mixed|null $key
     * @return int|array
     */
    public function stats($key = null)
    {
        return $this->client->stats($key);
    }

    public function isConnected(): bool
    {
        return $this->client->connected;
    }

    public function isRunning(): bool
    {
        return $this->recvCid > 0 || $this->sendCid > 0;
    }

    public function isStreamExist(int $streamId): bool
    {
        return isset($this->recvChannelMap[$streamId]);
    }

    public function getClient(): Http2Client
    {
        return $this->client;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function setTimeout(float $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * Open a new stream and return its id.
     *
     * @param array<string, string> $headers extra HTTP/2 headers (gRPC metadata)
     */
    public function openStream(
        string $path,
        ?string $data = '',
        string $method = '',
        bool $usePipelineRead = false,
        array $headers = [],
    ): int {
        $request         = new Request();
        $request->method = $method !== '' ? $method : (($data !== null && $data !== '') ? 'POST' : 'GET');
        $request->path   = $path;
        if ($data !== null && $data !== '') {
            $request->data = $data;
        }
        $request->pipeline = true;
        if ($usePipelineRead) {
            $request->usePipelineRead = true;
        }
        if ($headers !== []) {
            $request->headers = array_merge($request->headers, $headers);
        }

        return $this->send($request);
    }

    public function send(Request $request): int
    {
        $sendChannel    = $this->sendChannel;
        $sendRetChannel = $this->sendRetChannel;
        $sendLock       = $this->sendLock;
        if ($sendChannel === null || $sendRetChannel === null || $sendLock === null) {
            throw new \RuntimeException('Client is not started');
        }

        $sendLock->pop();
        try {
            if (!$sendChannel->push($request)) {
                throw new \RuntimeException('Client is closed');
            }
            $streamId = $sendRetChannel->pop();
        } finally {
            $sendLock->push(true);
        }

        // pop() returns false once the ret channel is closed (after close()); never leak that
        // into the int return type.
        return is_int($streamId) ? $streamId : 0;
    }

    public function write(int $streamId, ?string $data, bool $end = false): bool
    {
        $sendChannel    = $this->sendChannel;
        $sendRetChannel = $this->sendRetChannel;
        $sendLock       = $this->sendLock;
        if ($sendChannel === null || $sendRetChannel === null || $sendLock === null) {
            throw new \RuntimeException('Client is not started');
        }

        $sendLock->pop();
        try {
            if (!$sendChannel->push([$streamId, $data, $end])) {
                return false;
            }
            $ret = $sendRetChannel->pop();
        } finally {
            $sendLock->push(true);
        }

        return (bool) $ret;
    }

    /**
     * @return Http2Response|false
     */
    public function recv(int $streamId, ?float $timeout = null)
    {
        $channel = $this->recvChannelMap[$streamId] ?? null;
        if ($channel === null) {
            return false;
        }

        $response = $channel->pop($timeout === null ? $this->timeout : $timeout);
        // A timeout unregisters the stream so a later recv() does not block forever.
        if ($response === false && $channel->errCode === -1) {
            unset($this->recvChannelMap[$streamId]);
        }

        return $response;
    }

    public function close(): void
    {
        if ($this->isClosed()) {
            return;
        }
        $this->closed = true;
        $this->sendChannel?->close();
        $this->client->close();
        foreach ($this->recvChannelMap as $index => $channel) {
            unset($this->recvChannelMap[$index]);
            $channel->close();
        }
    }

    protected function start(): void
    {
        if ($this->recvCid !== 0 || $this->sendCid !== 0) {
            throw new \RuntimeException('Client has been started');
        }
        if ((int) Coroutine::getuid() <= 0) {
            throw new \RuntimeException('Client must be started in a coroutine');
        }
        if (!$this->client->connect()) {
            throw new \RuntimeException('Connect failed, error=' . $this->client->errMsg, $this->client->errCode);
        }

        // Build the channels before the loops are scheduled, so send()/write()/close() are safe
        // even if the caller does not yield between construction and the first call.
        $sendChannel    = $this->sendChannel = new Channel(1);
        $sendRetChannel = $this->sendRetChannel = new Channel(1);
        $sendLock       = $this->sendLock = new Channel(1);
        $sendLock->push(true);

        // receive loop
        Coroutine::create(function () use ($sendChannel): void {
            $this->recvCid = Coroutine::getuid();
            while (true) {
                if ($this->isClosed()) {
                    break;
                }

                /** @var Http2Response|false $response */
                $response = $this->client->recv(-1);
                if (static::$debug) {
                    var_dump($response);
                    if ($response === false && $this->client->errCode !== 0) {
                        var_dump($this->client->errCode);
                        var_dump($this->client->errMsg);
                    }
                }

                if ($response !== false) {
                    $channel = $this->recvChannelMap[$response->streamId] ?? null;
                    if ($channel !== null) {
                        $channel->push($response);
                        if (!$response->pipeline) {
                            unset($this->recvChannelMap[$response->streamId]);
                        }
                    } // else: the receiver is gone, discard the response

                    if (!$this->isClosed() || !empty($this->recvChannelMap)) {
                        continue;
                    }
                }

                if ($this->isClosed()) {
                    break;
                }

                // A dropped connection leaves the in-flight streams hanging: wake their waiters
                // before reconnecting, then retry with backoff (and endpoint failover when
                // configured). A successful reconnect continues the loop; a failed one ends it.
                foreach ($this->recvChannelMap as $index => $channel) {
                    unset($this->recvChannelMap[$index]);
                    $channel->close();
                }
                if (!$this->reconnect()) {
                    break;
                }
            }

            $this->recvCid = 0;
            $this->client->close();
            $sendChannel->close();
            foreach ($this->recvChannelMap as $index => $channel) {
                unset($this->recvChannelMap[$index]);
                $channel->close();
            }
        });

        // send loop
        Coroutine::create(function () use ($sendChannel, $sendRetChannel): void {
            $this->sendCid = Coroutine::getuid();
            while (true) {
                // With keepalive enabled, an idle pop timeout means it is time to ping. Pinging
                // here (in the send loop) keeps it serialized with send()/write() on the same
                // connection, which is required for safety.
                $sendData = $this->keepaliveTime > 0
                    ? $sendChannel->pop($this->keepaliveTime)
                    : $sendChannel->pop(-1);
                if ($sendData === false) {
                    if ($this->keepaliveTime > 0
                        && $sendChannel->errCode === -1 // timeout (not closed)
                        && !$this->isClosed()
                    ) {
                        $this->client->ping();
                        continue;
                    }
                    break;
                }
                if ($sendData instanceof Request) {
                    $ret = $this->client->send($sendData);
                    // Register the waiting channel before the reply can reach the recv loop.
                    if ($ret > 0) {
                        $this->recvChannelMap[$ret] = new Channel();
                    }
                } else {
                    $ret = $this->client->write(...$sendData);
                }
                $sendRetChannel->push($ret);
            }
            $sendRetChannel->close();
            $this->sendCid = 0;
        });
    }

    /**
     * @return array{host: string, port: int}|null
     */
    private static function parseEndpoint(string $endpoint): ?array
    {
        if (stripos($endpoint, 'unix:/') === 0) {
            return ['host' => $endpoint, 'port' => 0];
        }
        $parts = parse_url($endpoint);
        if (!$parts || !isset($parts['host']) || !isset($parts['port'])) {
            return null;
        }

        return ['host' => (string) $parts['host'], 'port' => (int) $parts['port']];
    }

    /**
     * Reconnect after a dropped connection. Each attempt rebuilds the underlying transport
     * (like grpc-go's resetTransport): a dropped Http2Client cannot reliably re-connect in
     * place because its internal state still reports "connected" over a dead socket. Retries
     * follow exponential backoff, rotating through configured endpoints for failover. The
     * default (one attempt, single endpoint) mirrors the original single connect() call.
     */
    private function reconnect(): bool
    {
        $this->backoff->reset();
        $count = count($this->endpoints);
        for ($attempt = 0; $attempt < $this->reconnectAttempts; $attempt++) {
            // Checked at the top of every iteration: close() may run in another coroutine while
            // backoff->sleep() yields, so this must be re-evaluated after each sleep.
            if ($this->isClosed()) {
                return false;
            }
            if ($attempt > 0 && $count > 1) {
                $this->endpointIndex = ($this->endpointIndex + 1) % $count;
            }

            $this->resetConnection();
            if ($this->client->connect()) {
                return true;
            }

            if ($attempt + 1 < $this->reconnectAttempts) {
                $this->backoff->sleep();
            }
        }

        return false;
    }

    /**
     * Close the dead transport and build a fresh Http2Client against the current endpoint.
     * All in-flight streams have already been woken up by the caller; the single connection
     * model (and recvChannelMap) is preserved.
     */
    private function resetConnection(): void
    {
        $this->client->close();
        $endpoint   = $this->endpoints[$this->endpointIndex];
        $this->host = $endpoint['host'];
        $this->port = $endpoint['port'];

        $this->client = new Http2Client($this->host, $this->port, $this->ssl);
        $this->client->set($this->clientOpts);
    }

    /**
     * Read through a method: close() may be called from another coroutine while the recv loop
     * runs, which static analysis cannot see on the property alone.
     */
    private function isClosed(): bool
    {
        return $this->closed;
    }
}
