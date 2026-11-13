<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Client;

use Swoole\Kafka\Config\CommonConfig;
use Swoole\Kafka\Protocol\AbstractRequest;
use Swoole\Kafka\Protocol\AbstractResponse;
use Swoole\Kafka\Protocol\ApiVersions\ApiVersionsResponseKey;
use Swoole\Kafka\Protocol\RequestHeader\RequestHeader;
use Swoole\Kafka\Protocol\ResponseHeader\ResponseHeader;
use Swoole\Kafka\Socket\SocketInterface;
use Swoole\Kafka\Socket\StreamSocket;

interface ClientInterface
{
    public function __construct(string $host, int $port, ?CommonConfig $config = null, string $socketClass = StreamSocket::class);

    public function getHost(): string;

    public function getPort(): int;

    public function getConfig(): CommonConfig;

    public function getSocket(): SocketInterface;

    /**
     * @param ApiVersionsResponseKey[] $apiKeys
     */
    public function setApiKeys(array $apiKeys): self;

    /**
     * @return ApiVersionsResponseKey[]|null
     */
    public function getApiKeys(): ?array;

    public function connect(): void;

    public function close(): bool;

    public function getRequestApiVersion(AbstractRequest $request): int;

    /**
     * Lower the highest version this client will use for an API after the broker rejected it.
     */
    public function downgradeApiVersion(int $apiKey): bool;

    /**
     * Send message to kafka server.
     *
     * If successful, return the correlationId
     */
    public function send(AbstractRequest $request, ?RequestHeader $header = null, bool $hasResponse = true): int;

    public function recv(?int $correlationId, ?ResponseHeader &$header = null): AbstractResponse;

    public function sendRecv(AbstractRequest $request, ?RequestHeader $requestHeader = null, ?ResponseHeader &$responseHeader = null): AbstractResponse;
}
