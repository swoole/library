<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Socket;

use Swoole\Coroutine\Client;
use Swoole\Kafka\Config\CommonConfig;
use Swoole\Kafka\Exception\ConnectionException;
use Swoole\Kafka\Exception\SocketException;
use Swoole\Kafka\Trace;

class SwooleSocket implements SocketInterface
{
    /**
     * read socket max length 5MB.
     *
     * @var int
     */
    public const READ_MAX_LENGTH = 5242880;

    /**
     * max write socket buffer.
     *
     * @var int
     */
    public const MAX_WRITE_BUFFER = 2048;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var \Swoole\Kafka\Config\CommonConfig|null
     */
    protected $config;

    /**
     * @var \Swoole\Coroutine\Client|null
     */
    protected $socket;

    /**
     * @var string
     */
    protected $receivedBuffer = '';

    public function __construct(string $host, int $port, ?CommonConfig $config = null)
    {
        $this->host = $host;
        $this->port = $port;
        if ($config === null) {
            $config = new CommonConfig();
        }
        $this->config = $config;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getConfig(): CommonConfig
    {
        return $this->config;
    }

    public function isConnected(): bool
    {
        return $this->socket && $this->socket->isConnected();
    }

    public function connect(): void
    {
        $config = $this->config;
        $client = new Client($this->getClientType());
        $client->set($this->getClientConfig());
        Trace::log(sprintf('socket connect %s:%d timeout=%s', $this->host, $this->port, $config->getConnectTimeout()));
        if ($client->connect($this->host, $this->port, $config->getConnectTimeout())) {
            Trace::log(sprintf('socket connect done %s:%d', $this->host, $this->port));
            $this->socket = $client;
        } else {
            throw new ConnectionException(sprintf('Could not connect to tcp://%s:%s (%s [%d])', $this->host, $this->port, $client->errMsg, $client->errCode));
        }
    }

    public function close(): bool
    {
        if ($this->socket) {
            $this->socket->close();
            $this->receivedBuffer = '';

            return true;
        }
        return false;
    }

    public function send(string $data, ?float $timeout = null): int
    {
        $result = $this->socket->send($data);
        if ($result === false) {
            $this->close();
            throw new SocketException(sprintf('Could not write data to stream, %s [%d]', $this->socket->errMsg, $this->socket->errCode));
        }

        return $result;
    }

    public function recv(int $length, ?float $timeout = null): string
    {
        $beginTime = microtime(true);
        if ($timeout === null) {
            $timeout = $this->config->getRecvTimeout();
        }
        Trace::log(sprintf('socket recv %s:%d len=%d timeout=%s', $this->host, $this->port, $length, $timeout));
        $leftTime = $timeout;
        while ($this->socket && !isset($this->receivedBuffer[$length - 1]) && ($timeout == -1 || $leftTime > 0)) {
            $buffer = $this->socket->recv($timeout);
            if ($buffer === '' || $buffer === false) {
                $code = $this->socket->errCode;
                $msg  = $this->socket->errMsg;
                $this->close();
                throw new SocketException(sprintf('Could not recv data from stream, %s [%d]', $msg, $code));
            }
            $this->receivedBuffer .= $buffer;
            if ($timeout > 0) {
                $leftTime = $timeout - (microtime(true) - $beginTime);
            }
        }

        if (isset($this->receivedBuffer[$length - 1])) {
            $result               = substr($this->receivedBuffer, 0, $length);
            $this->receivedBuffer = substr($this->receivedBuffer, $length);
            Trace::log(sprintf('socket recv done %s:%d len=%d', $this->host, $this->port, $length));

            return $result;
        }

        if ($this->socket->isConnected()) {
            $this->close();
            throw new SocketException('Could not recv data from stream');
        }

        return '';
    }

    protected function getClientType(): int
    {
        $clientType = \SWOOLE_SOCK_TCP;
        $ssl        = $this->getConfig()->getSsl();
        if ($ssl->getOpen()) {
            $clientType = $clientType | \SWOOLE_SSL;
        }

        return $clientType;
    }

    protected function getClientConfig(): array
    {
        $config       = $this->getConfig();
        $clientConfig = [
            'connect_timeout' => $config->getConnectTimeout(),
            'read_timeout'    => $config->getRecvTimeout(),
            'write_timeout'   => $config->getSendTimeout(),
        ];

        return array_merge($clientConfig, $config->getSsl()->getSwooleConfig($this->getHost()));
    }
}
