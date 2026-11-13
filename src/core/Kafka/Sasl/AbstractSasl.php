<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Sasl;

use Swoole\Kafka\Client\ClientInterface;
use Swoole\Kafka\Config\CommonConfig;
use Swoole\Kafka\Protocol\ErrorCode;
use Swoole\Kafka\Protocol\SaslAuthenticate\SaslAuthenticateRequest;
use Swoole\Kafka\Protocol\SaslAuthenticate\SaslAuthenticateResponse;
use Swoole\Kafka\Protocol\SaslHandshake\SaslHandshakeRequest;
use Swoole\Kafka\Protocol\SaslHandshake\SaslHandshakeResponse;

abstract class AbstractSasl implements SaslInterface
{
    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var CommonConfig
     */
    protected $config;

    public function __construct(ClientInterface $client, CommonConfig $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    protected function sendHandshake(string $mechanism): void
    {
        $handshakeRequest = new SaslHandshakeRequest();
        $handshakeRequest->setMechanism($mechanism);
        $correlationId = $this->client->send($handshakeRequest);
        /** @var SaslHandshakeResponse $handshakeResponse */
        $handshakeResponse = $this->client->recv($correlationId);
        ErrorCode::check($handshakeResponse->getErrorCode());
    }

    protected function sendAuthenticate(string $bytes): SaslAuthenticateResponse
    {
        $authenticateRequest = new SaslAuthenticateRequest();
        $authenticateRequest->setAuthBytes($bytes);
        $correlationId = $this->client->send($authenticateRequest);
        /** @var SaslAuthenticateResponse $authenticateResponse */
        $authenticateResponse = $this->client->recv($correlationId);
        ErrorCode::check($authenticateResponse->getErrorCode());

        return $authenticateResponse;
    }
}
