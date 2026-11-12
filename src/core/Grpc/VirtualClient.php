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

use Swoole\Coroutine\Http2\Client as Http2Client;
use Swoole\Http2\Response as Http2Response;

/**
 * Proxy over Client. Generated stubs extend BaseStub (which extends this class), so several
 * different generated clients can share one underlying connection by passing ['use' => $client].
 */
class VirtualClient
{
    private ?Client $client = null;

    /** False when the client was supplied with ['use' => ...] or use(); that caller closes it. */
    private bool $ownsClient = true;

    public function __construct(string $hostname, array $opts = [])
    {
        if (!empty($opts['use'])) {
            if (!$opts['use'] instanceof Client) {
                throw new \InvalidArgumentException('parameter use must be instanceof Swoole\Grpc\Client');
            }
            $this->use($opts['use']);
        } else {
            $this->client = new Client($hostname, $opts);
        }
    }

    public function __destruct()
    {
        // A stub that opened the connection closes it. One that borrowed a client must not: other
        // stubs may still be using it. close() itself still forwards, which is the explicit call.
        if ($this->ownsClient && $this->client !== null) {
            $this->client->close();
        }
    }

    public function __get(string $name)
    {
        return $this->client()->__get($name);
    }

    public function getGrpcClient(): ?Client
    {
        return $this->client;
    }

    public function use(Client $client): void
    {
        if ($this->ownsClient && $this->client !== null && $this->client !== $client) {
            $this->client->close();
        }
        $this->ownsClient = false;
        $this->client     = $client;
    }

    public static function numStats(): array
    {
        return Client::numStats();
    }

    public static function debug(bool $enable = true): void
    {
        Client::debug($enable);
    }

    /**
     * @param mixed|null $key
     * @return int|array
     */
    public function stats($key = null)
    {
        return $this->client()->stats($key);
    }

    public function isConnected(): bool
    {
        return $this->client()->isConnected();
    }

    public function isRunning(): bool
    {
        return $this->client()->isRunning();
    }

    public function isStreamExist(int $streamId): bool
    {
        return $this->client()->isStreamExist($streamId);
    }

    public function getClient(): Http2Client
    {
        return $this->client()->getClient();
    }

    public function getTimeout(): float
    {
        return $this->client()->getTimeout();
    }

    public function setTimeout(float $timeout): void
    {
        $this->client()->setTimeout($timeout);
    }

    /**
     * @param array<string, string> $headers
     */
    public function openStream(
        string $path,
        ?string $data = '',
        string $method = '',
        bool $usePipelineRead = false,
        array $headers = [],
    ): int {
        return $this->client()->openStream($path, $data, $method, $usePipelineRead, $headers);
    }

    public function send(Request $request): int
    {
        return $this->client()->send($request);
    }

    public function write(int $streamId, ?string $data, bool $end = false): bool
    {
        return $this->client()->write($streamId, $data, $end);
    }

    /**
     * @return Http2Response|false
     */
    public function recv(int $streamId, ?float $timeout = null)
    {
        return $this->client()->recv($streamId, $timeout);
    }

    public function consumeRecvFailure(int $streamId): int
    {
        return $this->client()->consumeRecvFailure($streamId);
    }

    public function abandon(int $streamId): void
    {
        $this->client()->abandon($streamId);
    }

    public function close(): void
    {
        $this->client()->close();
    }

    /**
     * @throws \RuntimeException when no underlying client has been set
     */
    private function client(): Client
    {
        if ($this->client === null) {
            throw new \RuntimeException('VirtualClient has no underlying Client');
        }

        return $this->client;
    }
}
