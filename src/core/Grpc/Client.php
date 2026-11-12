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
use Swoole\Http2\Response as Http2Response;

/**
 * A multiplexed gRPC client: one HTTP/2 connection, one send loop and one recv loop, with every
 * in-flight stream waiting on its own channel. All requests must be made from coroutines.
 */
class Client
{
    /** Default recv() timeout; -1 waits indefinitely. */
    public const DEFAULT_TIMEOUT = -1.0;

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

    protected Http2Client $client;

    protected float $timeout = self::DEFAULT_TIMEOUT;

    public function __construct(string $hostname, array $opts = [])
    {
        if (stripos($hostname, 'unix:/') === 0) {
            $parts = ['host' => $hostname, 'port' => 0];
        } else {
            $parts = parse_url($hostname);
        }

        if (!$parts || !isset($parts['host']) || !isset($parts['port'])) {
            throw new \InvalidArgumentException("The hostname {$hostname} is illegal!");
        }

        $this->host = (string) $parts['host'];
        $this->port = (int) $parts['port'];

        $defaultOpts = [
            'timeout'       => self::DEFAULT_TIMEOUT,
            'ssl'           => false,
            'ssl_host_name' => '',
        ];
        $this->opts        = $opts + $defaultOpts;
        $this->timeout     = (float) $this->opts['timeout'];
        $this->ssl         = (bool) $this->opts['ssl'] || (bool) $this->opts['ssl_host_name'];
        $this->opts['ssl'] = $this->ssl;

        $this->client = new Http2Client($this->host, $this->port, $this->ssl);
        $this->client->set($this->opts);
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
                    $needBreak = true;
                } else {
                    // A dropped connection leaves the in-flight streams hanging: wake their
                    // waiters before trying to reconnect.
                    foreach ($this->recvChannelMap as $index => $channel) {
                        unset($this->recvChannelMap[$index]);
                        $channel->close();
                    }
                    $needBreak = !$this->client->connect();
                }

                if ($needBreak) {
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
                $sendData = $sendChannel->pop(-1);
                if ($sendData === false) {
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
     * Read through a method: close() may be called from another coroutine while the recv loop
     * runs, which static analysis cannot see on the property alone.
     */
    private function isClosed(): bool
    {
        return $this->closed;
    }
}
