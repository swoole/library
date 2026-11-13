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

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Kafka\Config\CommonConfig;
use Swoole\Kafka\Exception\SocketException;
use Swoole\Kafka\Protocol\AbstractRequest;
use Swoole\Kafka\Protocol\AbstractResponse;
use Swoole\Kafka\Protocol\ApiKeys;
use Swoole\Kafka\Protocol\KafkaRequest;
use Swoole\Kafka\Protocol\RequestHeader\RequestHeader;
use Swoole\Kafka\Protocol\ResponseHeader\ResponseHeader;
use Swoole\Kafka\Protocol\Type\Int32;
use Swoole\Kafka\Socket\SwooleSocket;

class SwooleClient extends SyncClient
{
    /**
     * @var bool
     */
    protected $coRecvRunning = false;

    /**
     * @var \Swoole\Coroutine\Channel[]
     */
    protected $recvChannels = [];

    /**
     * @var int|bool
     */
    private $recvCoId = false;

    /**
     * @var bool
     */
    private $connected = false;

    public function __construct(string $host, int $port, ?CommonConfig $config = null, string $socketClass = SwooleSocket::class)
    {
        parent::__construct($host, $port, $config, $socketClass);
    }

    public function connect(): void
    {
        parent::connect();
        $this->connected = true;
    }

    public function close(): bool
    {
        $this->connected = false;
        foreach ($this->recvChannels as $channel) {
            $channel->close();
        }

        $this->recvChannels = [];

        return $this->socket->close();
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
        $kafkaRequest                       = new KafkaRequest($request, $header);
        $correlationId                      = $header->getCorrelationId();
        $this->recvChannels[$correlationId] = new Channel(1);
        if ($hasResponse) {
            $this->waitResponseMaps[$correlationId] = [
                'apiKey'           => $apiKey,
                'apiVersion'       => $header->getRequestApiVersion(),
                'flexibleVersions' => $request->getFlexibleVersions(),
            ];
        }
        $this->socket->send($kafkaRequest->pack());

        return $correlationId;
    }

    public function recv(?int $correlationId, ?ResponseHeader &$header = null): AbstractResponse
    {
        if (!isset($this->waitResponseMaps[$correlationId])) {
            throw new \InvalidArgumentException(sprintf('Invalid correlationId %s', $correlationId));
        }
        $mapData  = $this->waitResponseMaps[$correlationId];
        $recvCoId = $this->recvCoId;
        if (!$recvCoId || ($recvCoId !== true && !Coroutine::exists($recvCoId))) {
            $this->startRecvCo();
        }
        if (isset($this->recvChannels[$correlationId])) {
            $channel = $this->recvChannels[$correlationId];
        } else {
            $this->recvChannels[$correlationId] = $channel = new Channel(1);
        }
        $data = $channel->pop($this->getConfig()->getRecvTimeout());
        unset($this->recvChannels[$correlationId]);
        if ($data === false) {
            throw new \RuntimeException('Recv data timeout');
        }
        if ($data instanceof \Exception) {
            throw $data;
        }

        $header = new ResponseHeader();
        $header->unpack($data, $size, ResponseHeader::parseVersion($mapData['apiVersion'], $mapData['flexibleVersions']));
        $data = substr($data, $size);

        $result = ApiKeys::createResponse($mapData['apiKey'], $data, $mapData['apiVersion']);
        unset($this->waitResponseMaps[$correlationId]);

        return $result;
    }

    private function startRecvCo(): void
    {
        $this->coRecvRunning = true;
        $this->recvCoId      = true;
        $this->recvCoId      = Coroutine::create(function () {
            while ($this->coRecvRunning) {
                try {
                    $data = $this->socket->recv(4, -1);
                    if ($data === '') {
                        break;
                    }
                    $length        = Int32::unpack($data);
                    $data          = $this->socket->recv($length);
                    $correlationId = Int32::unpack($data);
                    if (isset($this->recvChannels[$correlationId])) {
                        $this->recvChannels[$correlationId]->push($data);
                    }
                } catch (\Exception $e) {
                    if ($e instanceof SocketException && !$this->connected) {
                        return;
                    }

                    $callback = $this->getConfig()->getExceptionCallback();
                    if ($callback) {
                        $callback($e);
                    }

                    $this->close();
                }
            }
        });
    }
}
