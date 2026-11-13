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
use Swoole\Kafka\Exception\SocketException;
use Swoole\Kafka\Exception\UnsupportedApiKeyException;
use Swoole\Kafka\Exception\UnsupportedApiVersionException;
use Swoole\Kafka\Protocol\AbstractRequest;
use Swoole\Kafka\Protocol\AbstractResponse;
use Swoole\Kafka\Protocol\ApiKeys;
use Swoole\Kafka\Protocol\ApiVersions\ApiVersionsRequest;
use Swoole\Kafka\Protocol\ApiVersions\ApiVersionsResponse;
use Swoole\Kafka\Protocol\ApiVersions\ApiVersionsResponseKey;
use Swoole\Kafka\Protocol\ErrorCode;
use Swoole\Kafka\Protocol\KafkaRequest;
use Swoole\Kafka\Protocol\RequestHeader\RequestHeader;
use Swoole\Kafka\Protocol\ResponseHeader\ResponseHeader;
use Swoole\Kafka\Protocol\Type\Int32;
use Swoole\Kafka\Sasl\SaslInterface;
use Swoole\Kafka\Socket\SocketInterface;
use Swoole\Kafka\Socket\StreamSocket;
use Swoole\Kafka\Trace;

class SyncClient implements ClientInterface
{
    /**
     * @var SocketInterface
     */
    protected $socket;

    /**
     * @var ApiVersionsResponseKey[]
     */
    protected $apiKeys;

    /**
     * @var array
     */
    protected $waitResponseMaps;

    /**
     * @var int
     */
    protected $correlationIdIncrValue = 0;

    /**
     * @var bool
     */
    private $reconnecting = false;

    public function __construct(string $host, int $port, ?CommonConfig $config = null, string $socketClass = StreamSocket::class)
    {
        $this->socket = new $socketClass($host, $port, $config);
    }

    public function getHost(): string
    {
        return $this->socket->getHost();
    }

    public function getPort(): int
    {
        return $this->socket->getPort();
    }

    public function getConfig(): CommonConfig
    {
        return $this->socket->getConfig();
    }

    public function getSocket(): SocketInterface
    {
        return $this->socket;
    }

    /**
     * @param ApiVersionsResponseKey[] $apiKeys
     */
    public function setApiKeys(array $apiKeys): ClientInterface
    {
        $result = [];
        foreach ($apiKeys as $item) {
            $result[$item->getApiKey()] = $item;
        }
        $this->apiKeys = $result;

        return $this;
    }

    /**
     * @return ApiVersionsResponseKey[]|null
     */
    public function getApiKeys(): ?array
    {
        return $this->apiKeys;
    }

    public function connect(): void
    {
        Trace::log(sprintf('connect %s:%d', $this->getHost(), $this->getPort()));
        $this->socket->connect();
        $this->waitResponseMaps = [];
        Trace::log(sprintf('connect api-versions %s:%d', $this->getHost(), $this->getPort()));
        $this->updateApiVersions();
        Trace::log(sprintf('connect auth %s:%d', $this->getHost(), $this->getPort()));
        $this->sendAuthInfo();
        Trace::log(sprintf('connect ready %s:%d', $this->getHost(), $this->getPort()));
    }

    public function close(): bool
    {
        return $this->socket->close();
    }

    public function getRequestApiVersion(AbstractRequest $request): int
    {
        $apiKey       = $request->getRequestApiKey();
        $apiKeyConfig = $this->apiKeys[$apiKey] ?? null;
        if (!$apiKeyConfig) {
            if ($apiKey === ApiKeys::PROTOCOL_API_VERSIONS) {
                return 1;
            }
            throw new UnsupportedApiKeyException(sprintf('Unsupported apikey %s', $apiKey));
        }
        $requestMaxSupportedVersion = $request->getMaxSupportedVersion();
        if ($apiKeyConfig->getMinVersion() > $request->getMaxSupportedVersion()) {
            throw new UnsupportedApiVersionException(sprintf('The version of Api %s must be >= v%s and <= v%s, the latest version currently supported is v%s', $request->getApiKeyText(), $apiKeyConfig->getMinVersion(), $apiKeyConfig->getMaxVersion(), $request->getMaxSupportedVersion()));
        }
        $apiKeyMaxVersion = $apiKeyConfig->getMaxVersion();
        if ($requestMaxSupportedVersion <= $apiKeyMaxVersion) {
            return $requestMaxSupportedVersion;
        }
        return $apiKeyMaxVersion;
    }

    public function downgradeApiVersion(int $apiKey): bool
    {
        if (!isset($this->apiKeys[$apiKey])) {
            return false;
        }
        $apiKeyConfig = $this->apiKeys[$apiKey];
        if ($apiKeyConfig->getMaxVersion() <= $apiKeyConfig->getMinVersion()) {
            return false;
        }
        $apiKeyConfig->setMaxVersion($apiKeyConfig->getMaxVersion() - 1);

        return true;
    }

    /**
     * Send message to kafka server.
     *
     * If successful, return the correlationId
     */
    public function send(AbstractRequest $request, ?RequestHeader $header = null, bool $hasResponse = true): int
    {
        $apiKey = $request->getRequestApiKey();
        if ($header === null) {
            $header = new RequestHeader();
            $header->setRequestApiKey($apiKey);
            $header->setRequestApiVersion($this->getRequestApiVersion($request));
            $header->setClientId($this->getConfig()->getClientId());
            $header->setCorrelationId(++$this->correlationIdIncrValue);
        }
        $kafkaRequest  = new KafkaRequest($request, $header);
        $correlationId = $header->getCorrelationId();
        Trace::log(sprintf('send %s correlation=%d %s:%d', $request->getApiKeyText(), $correlationId, $this->getHost(), $this->getPort()));
        $this->socket->send($kafkaRequest->pack());

        if ($hasResponse) {
            $this->waitResponseMaps[$correlationId] = [
                'apiKey'           => $apiKey,
                'apiVersion'       => $header->getRequestApiVersion(),
                'flexibleVersions' => $request->getFlexibleVersions(),
            ];
        }

        return $correlationId;
    }

    public function recv(?int $correlationId, ?ResponseHeader &$header = null): AbstractResponse
    {
        if (!isset($this->waitResponseMaps[$correlationId])) {
            throw new \InvalidArgumentException(sprintf('Invalid correlationId %s', $correlationId));
        }
        $mapData = $this->waitResponseMaps[$correlationId];
        Trace::log(sprintf('recv correlation=%s %s:%d', $correlationId, $this->getHost(), $this->getPort()));
        $data = $this->readFrame();
        Trace::log(sprintf('recv done correlation=%s %s:%d', $correlationId, $this->getHost(), $this->getPort()));
        $header  = new ResponseHeader();
        $header->unpack($data, $size, ResponseHeader::parseVersion($mapData['apiVersion'], $mapData['flexibleVersions'], $mapData['apiKey']));
        $data = substr($data, $size);

        $result = ApiKeys::createResponse($mapData['apiKey'], $data, $mapData['apiVersion']);
        unset($this->waitResponseMaps[$correlationId]);

        return $result;
    }

    public function sendRecv(AbstractRequest $request, ?RequestHeader $requestHeader = null, ?ResponseHeader &$responseHeader = null): AbstractResponse
    {
        try {
            $correlationId = $this->send($request, $requestHeader);

            return $this->recv($correlationId, $responseHeader);
        } catch (SocketException $e) {
            if ($this->reconnecting) {
                throw $e;
            }
            // The broker drops an idle connection. Open another and send the request once more.
            Trace::log(sprintf('sendRecv reconnect %s %s:%d %s', $request->getApiKeyText(), $this->getHost(), $this->getPort(), $e->getMessage()));
            $this->reconnecting = true;
            try {
                $this->close();
                $this->connect();
                $correlationId = $this->send($request, $requestHeader);

                return $this->recv($correlationId, $responseHeader);
            } finally {
                $this->reconnecting = false;
            }
        }
    }

    /**
     * Read one response frame. A short or nonsense length is a dead connection, not a Kafka error code.
     */
    protected function readFrame(): string
    {
        $data = $this->socket->recv(4);
        if (\strlen($data) !== 4) {
            $this->close();
            throw new SocketException('Connection closed while reading the response length');
        }
        $length = Int32::unpack($data);
        if ($length < 0 || $length > StreamSocket::READ_MAX_LENGTH) {
            $this->close();
            throw new SocketException(sprintf('Invalid response length %d', $length));
        }
        $data = $this->socket->recv($length);
        if (\strlen($data) !== $length) {
            $this->close();
            throw new SocketException('Connection closed while reading the response');
        }

        return $data;
    }

    protected function updateApiVersions(): void
    {
        $request = new ApiVersionsRequest();
        // Version 3 carries the client software name. Kafka rejects an empty one, or one that
        // is not letters, digits, '.' and '-', as INVALID_REQUEST. The first connection sends
        // version 1 and never hits that check; a reconnect sends the negotiated version.
        $request->setClientSoftwareName('swoole');
        $request->setClientSoftwareVersion(self::clientSoftwareVersion());
        $correlationId = $this->send($request);
        /** @var ApiVersionsResponse $response */
        $response = $this->recv($correlationId);
        ErrorCode::check($response->getErrorCode());
        $this->setApiKeys($response->getApiKeys());
    }

    protected function sendAuthInfo(): void
    {
        $config = $this->getConfig()->getSasl();
        if (!isset($config['type']) || empty($config['type'])) {
            return;
        }
        $class = new $config['type']($this, $this->getConfig());
        if (!$class instanceof SaslInterface) {
            return;
        }
        $class->auth();
    }

    /**
     * Kafka accepts a software version of letters, digits, '.' and '-' only.
     */
    private static function clientSoftwareVersion(): string
    {
        $version = phpversion('swoole');
        if (\is_string($version) && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $version) === 1) {
            return $version;
        }

        return '0';
    }
}
