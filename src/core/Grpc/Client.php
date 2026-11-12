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

    public const RECV_OK = 0;

    public const RECV_TIMEOUT = 1;

    public const RECV_CLOSED = 2;

    public const RECV_CANCELED = 3;

    public const RECV_MISSING = 4;

    public const RECV_OVERFLOW = 5;

    private const CONTROL_KEYS = [
        'endpoints'                  => true,
        'keepalive_time'             => true,
        'reconnect_attempts'         => true,
        'reconnect_backoff_initial'  => true,
        'reconnect_backoff_max'      => true,
    ];

    /** Frames buffered per stream before a slow reader forces that stream to be dropped. */
    private const STREAM_CHANNEL_SIZE = 16;

    /**
     * Swoole treats a push timeout of 0 as "wait forever", so a full channel has to be given a
     * positive timeout. One millisecond is enough to hand the slot to a reader that is already
     * popping, and short enough that a stalled stream does not freeze the shared recv loop.
     */
    private const CHANNEL_PUSH_TIMEOUT = 0.001;

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

    /**
     * Streams whose waiter has gone away (timeout, cancel, overflow). A late frame for one of
     * these must be discarded: buffering it again would park a channel nobody reads.
     *
     * @var array<int, true>
     */
    protected array $abandonedStreams = [];

    /**
     * Why the last recv() on a stream failed. Read once via consumeRecvFailure().
     *
     * @var array<int, int>
     */
    protected array $recvFailures = [];

    /**
     * Bumped every time the transport is replaced or the client is closed. A send that started
     * on the previous generation must not register its stream id on the new connection.
     */
    protected int $connectionGeneration = 0;

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
                if (!is_string($endpoint)) {
                    throw new \InvalidArgumentException('The endpoint is illegal!');
                }
                $parsed = self::parseEndpoint($endpoint);
                if ($parsed === null) {
                    throw new \InvalidArgumentException("The endpoint {$endpoint} is illegal!");
                }
                $this->endpoints[] = $parsed;
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
        // The recv/send loops only hold a WeakReference, so dropping the last external reference
        // gets here and must release the socket. An instance built without the constructor has
        // no transport to close.
        $transport = new \ReflectionProperty(self::class, 'client');
        if ($transport->isInitialized($this) && !$this->closed) {
            $this->close();
        }
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
            $this->recvFailures[$streamId] ??= isset($this->abandonedStreams[$streamId])
                ? self::RECV_CLOSED
                : self::RECV_MISSING;
            return false;
        }

        $response = $channel->pop($timeout === null ? $this->timeout : $timeout);
        if ($response === false) {
            $errCode = $channel->errCode;
            if ($errCode === SWOOLE_CHANNEL_TIMEOUT) {
                $this->abandon($streamId);
                $this->recvFailures[$streamId] = self::RECV_TIMEOUT;
            } elseif ($errCode === SWOOLE_CHANNEL_CANCELED) {
                unset($this->recvChannelMap[$streamId]);
                $this->recvFailures[$streamId] ??= self::RECV_CANCELED;
            } else {
                unset($this->recvChannelMap[$streamId]);
                $this->recvFailures[$streamId] = $this->recvFailures[$streamId] ?? self::RECV_CLOSED;
            }
            return false;
        }

        unset($this->recvFailures[$streamId]);
        // The terminal response has been handed to its waiter. Until then the channel stays in the
        // map, so a frame that arrives before recv() is called is still there to be popped.
        if (!$response->pipeline) {
            unset($this->recvChannelMap[$streamId]);
        }

        return $response;
    }

    /**
     * The reason the last failed recv() on this stream returned false.
     */
    public function consumeRecvFailure(int $streamId): int
    {
        $failure = $this->recvFailures[$streamId] ?? self::RECV_OK;
        unset($this->recvFailures[$streamId]);
        return $failure;
    }

    /**
     * Drop a stream locally. The peer is not told (the HTTP/2 client has no per-stream reset);
     * later frames for this id are discarded instead of blocking the shared recv loop.
     */
    public function abandon(int $streamId): void
    {
        $channel = $this->recvChannelMap[$streamId] ?? null;
        unset($this->recvChannelMap[$streamId]);
        $this->abandonedStreams[$streamId] = true;
        $channel?->close();
    }

    public function close(): void
    {
        if ($this->isClosed()) {
            return;
        }
        $this->closed = true;
        // In-flight send()/write() calls observe the new generation and discard a stream id that
        // belonged to the socket being closed.
        $this->connectionGeneration++;
        $this->sendChannel?->close();
        $this->client->close();
        $this->failStreams(self::RECV_CLOSED);
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

        // Static closures, plus a weak reference, so the loops do not keep the client alive after
        // the last external reference is dropped. An ordinary closure created here would bind $this.
        $self = \WeakReference::create($this);

        // receive loop
        Coroutine::create(static function () use ($self, $sendChannel): void {
            $client = $self->get();
            if (!$client instanceof self) {
                return;
            }
            $client->recvCid = Coroutine::getuid();
            while (true) {
                $client = $self->get();
                if (!$client instanceof self || $client->isClosed()) {
                    break;
                }
                $http = $client->getClient();
                // Drop the strong reference before the blocking read, so a client nobody holds
                // anymore can be destroyed (and close this socket) while the read waits.
                unset($client);

                /** @var Http2Response|false $response */
                $response = $http->recv(-1);
                $client   = $self->get();
                if (!$client instanceof self) {
                    break;
                }
                if (static::$debug) {
                    var_dump($response);
                    if ($response === false && $http->errCode !== 0) {
                        var_dump($http->errCode);
                        var_dump($http->errMsg);
                    }
                }

                if ($response !== false) {
                    $client->deliver($response);
                    if (!$client->isClosed() || $client->recvChannelMap !== []) {
                        continue;
                    }
                }

                if ($client->isClosed()) {
                    break;
                }

                // A dropped connection leaves the in-flight streams hanging: wake their waiters
                // before reconnecting, then retry with backoff (and endpoint failover when
                // configured). A successful reconnect continues the loop; a failed one ends it.
                $client->failStreams(self::RECV_CLOSED);
                if (!$client->reconnect()) {
                    break;
                }
            }

            $client = $self->get();
            if ($client instanceof self) {
                $client->recvCid = 0;
                $client->getClient()->close();
                $client->failStreams(self::RECV_CLOSED);
            }
            $sendChannel->close();
        });

        // send loop
        Coroutine::create(static function () use ($self, $sendChannel, $sendRetChannel): void {
            $client = $self->get();
            if (!$client instanceof self) {
                return;
            }
            $client->sendCid = Coroutine::getuid();
            while (true) {
                $client = $self->get();
                if (!$client instanceof self) {
                    break;
                }
                // With keepalive enabled, an idle pop timeout means it is time to ping. Pinging
                // here (in the send loop) keeps it serialized with send()/write() on the same
                // connection, which is required for safety.
                $keepalive = $client->keepaliveTime;
                unset($client);
                $sendData = $keepalive > 0 ? $sendChannel->pop($keepalive) : $sendChannel->pop(-1);
                $client   = $self->get();
                if (!$client instanceof self) {
                    break;
                }
                if ($sendData === false) {
                    if ($keepalive > 0
                        && $sendChannel->errCode === SWOOLE_CHANNEL_TIMEOUT
                        && !$client->isClosed()
                    ) {
                        $http = $client->getClient();
                        unset($client);
                        $http->ping();
                        continue;
                    }
                    break;
                }
                $generation = $client->connectionGeneration;
                $http       = $client->getClient();
                unset($client);
                if ($sendData instanceof Request) {
                    $ret    = $http->send($sendData);
                    $client = $self->get();
                    // The response may already have been buffered by the recv loop while send()
                    // yielded. A generation change means this stream id belongs to a transport
                    // that has since been replaced.
                    if (!$client instanceof self || $client->connectionGeneration !== $generation) {
                        $ret = 0;
                    } elseif (is_int($ret) && $ret > 0 && !isset($client->recvChannelMap[$ret])) {
                        $client->recvChannelMap[$ret] = new Channel(self::STREAM_CHANNEL_SIZE);
                    }
                } else {
                    $ret    = $http->write(...$sendData);
                    $client = $self->get();
                    if (!$client instanceof self || $client->connectionGeneration !== $generation) {
                        $ret = false;
                    }
                }
                unset($client);
                $sendRetChannel->push($ret);
            }
            $sendRetChannel->close();
            $client = $self->get();
            if ($client instanceof self) {
                $client->sendCid = 0;
            }
        });
    }

    /**
     * Hand one HTTP/2 response to the coroutine waiting on its stream.
     *
     * A response can arrive before the send loop has registered the stream: send() yields on
     * socket writes, and the recv loop runs in between. Client-initiated streams have odd ids, so
     * an unknown odd id is buffered for the sender to pick up. Even ids and streams that were
     * abandoned are discarded.
     */
    private function deliver(Http2Response $response): void
    {
        $streamId = $response->streamId;
        if (isset($this->abandonedStreams[$streamId])) {
            if (!$response->pipeline) {
                unset($this->abandonedStreams[$streamId]);
            }
            return;
        }

        $channel = $this->recvChannelMap[$streamId] ?? null;
        if ($channel === null) {
            if ($streamId % 2 !== 1) {
                return;
            }
            $channel = $this->recvChannelMap[$streamId] = new Channel(self::STREAM_CHANNEL_SIZE);
        }

        if ($channel->push($response, self::CHANNEL_PUSH_TIMEOUT)) {
            return;
        }

        // The reader is too far behind. Fail this stream and keep reading the others.
        $this->recvFailures[$streamId] = self::RECV_OVERFLOW;
        $this->abandon($streamId);
    }

    /**
     * Wake every waiter and forget the streams. Stream ids restart after a reconnect, so the
     * abandoned-stream set has to be cleared with them.
     */
    private function failStreams(int $reason): void
    {
        foreach ($this->recvChannelMap as $streamId => $channel) {
            $this->recvFailures[$streamId] = $reason;
            unset($this->recvChannelMap[$streamId]);
            $channel->close();
        }
        $this->abandonedStreams = [];
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
        // Before close() yields into the send loop: a send still inside the old transport must
        // observe the new generation and drop the stream id it gets back.
        $this->connectionGeneration++;
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
