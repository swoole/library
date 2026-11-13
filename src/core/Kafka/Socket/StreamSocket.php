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

use Swoole\Kafka\Config\CommonConfig;
use Swoole\Kafka\Exception\ConnectionException;
use Swoole\Kafka\Exception\SocketException;
use Swoole\Kafka\Trace;

class StreamSocket implements SocketInterface
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
     * @var resource|null
     */
    protected $socket;

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
        return $this->socket !== null;
    }

    public function connect(): void
    {
        $uri     = $this->getURI();
        $timeout = $this->config->getConnectTimeout();
        if ($timeout < 0) {
            $timeout = null;
        }
        Trace::log(sprintf('socket connect %s timeout=%s', $uri, $timeout === null ? '-1' : $timeout));
        $socket = stream_socket_client(
            $uri,
            $errno,
            $errstr,
            $timeout,
            \STREAM_CLIENT_CONNECT,
            $this->getContext()
        );

        if (!\is_resource($socket)) {
            throw new ConnectionException(sprintf('Could not connect to %s (%s [%d])', $uri, $errstr, $errno));
        }
        $this->socket = $socket;
    }

    public function close(): bool
    {
        if (\is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;

            return true;
        }
        return false;
    }

    public function send(string $data, ?float $timeout = null): int
    {
        // fwrite to a socket may be partial, so loop until we
        // are done with the entire buffer
        $failedAttempts = 0;
        $bytesWritten   = 0;

        $bytesToWrite = \strlen($data);

        if ($timeout === null) {
            $timeout = $this->config->getSendTimeout();
        }
        while ($bytesWritten < $bytesToWrite) {
            // wait for stream to become available for writing
            $writable = $this->select([$this->socket], $timeout, false);

            if ($writable === false) {
                $this->close();
                throw new SocketException('Could not write ' . $bytesToWrite . ' bytes to stream');
            }

            if ($writable === 0) {
                $res = $this->getMetaData();
                $this->close();
                if (!empty($res['timed_out'])) {
                    throw new SocketException('Timed out writing ' . $bytesToWrite . ' bytes to stream after writing ' . $bytesWritten . ' bytes');
                }

                throw new SocketException('Could not write ' . $bytesToWrite . ' bytes to stream');
            }

            if ($bytesToWrite - $bytesWritten > self::MAX_WRITE_BUFFER) {
                // write max buffer size
                $wrote = fwrite($this->socket, substr($data, $bytesWritten, self::MAX_WRITE_BUFFER));
            } else {
                // write remaining buffer bytes to stream
                $wrote = fwrite($this->socket, substr($data, $bytesWritten));
            }

            if ($wrote === false) {
                $this->close();
                throw new SocketException('Could not write ' . \strlen($data) . ' bytes to stream, completed writing only ' . $bytesWritten . ' bytes');
            }

            if ($wrote === 0) {
                // Increment the number of times we have failed
                $failedAttempts++;

                if ($failedAttempts > $this->config->getMaxWriteAttempts()) {
                    $this->close();
                    throw new SocketException('After ' . $failedAttempts . ' attempts could not write ' . \strlen($data) . ' bytes to stream, completed writing only ' . $bytesWritten . ' bytes');
                }
            } else {
                // If we wrote something, reset our failed attempt counter
                $failedAttempts = 0;
            }

            $bytesWritten += $wrote;
        }

        return $bytesWritten;
    }

    public function recv(int $length, ?float $timeout = null): string
    {
        if ($length > self::READ_MAX_LENGTH) {
            throw new SocketException(sprintf('Invalid length %d given, it should be lesser than or equals to %d', $length, self::READ_MAX_LENGTH));
        }

        if ($timeout === null) {
            $timeout = $this->config->getRecvTimeout();
        }
        Trace::log(sprintf('socket recv %s len=%d timeout=%s', $this->getURI(), $length, $timeout));
        $readable = $this->select([$this->socket], $timeout);
        Trace::log(sprintf('socket recv selected %s len=%d readable=%s', $this->getURI(), $length, $readable === false ? 'false' : (string) $readable));

        if ($readable === false) {
            $this->close();
            throw new SocketException(sprintf('Could not read %d bytes from stream (not readable)', $length));
        }

        if ($readable === 0) { // select timeout
            $res = $this->getMetaData();
            $this->close();

            if (!empty($res['timed_out'])) {
                throw new SocketException(sprintf('Timed out reading %d bytes from stream', $length));
            }

            throw new SocketException(sprintf('Could not read %d bytes from stream (not readable)', $length));
        }

        $remainingBytes = $length;
        $data           = $chunk = '';

        while ($remainingBytes > 0) {
            $chunk = fread($this->socket, $remainingBytes);

            if ($chunk === false || \strlen($chunk) === 0) {
                // Zero bytes because of EOF?
                if (feof($this->socket)) {
                    $this->close();
                    throw new SocketException(sprintf('Unexpected EOF while reading %d bytes from stream (no data)', $length));
                }
                // Otherwise wait for bytes
                $readable = $this->select([$this->socket], $timeout);
                if ($readable !== 1) {
                    $this->close();
                    throw new SocketException(sprintf('Timed out while reading %d bytes from stream, %d bytes are still needed', $length, $remainingBytes));
                }

                continue; // attempt another read
            }

            $data .= $chunk;
            $remainingBytes -= \strlen($chunk);
        }

        return $data;
    }

    /**
     * @return int|false
     */
    protected function select(array $sockets, float $timeout, bool $isRead = true)
    {
        $null    = [];
        $seconds = (int) $timeout;

        if ($seconds < 0) {
            $seconds = $microSeconds = null;
        } else {
            $microSeconds = max((int) $seconds * 100000, 1);
        }

        if ($isRead) {
            return stream_select($sockets, $null, $null, $seconds, $microSeconds);
        }

        return stream_select($null, $sockets, $null, $seconds, $microSeconds);
    }

    protected function getMetaData(): array
    {
        return stream_get_meta_data($this->socket);
    }

    /**
     * @return resource
     */
    protected function getContext()
    {
        return stream_context_create([
            'ssl' => $this->config->getSsl()->getStreamConfig($this->getHost()),
        ]);
    }

    protected function getURI(): string
    {
        $protocol = 'tcp';
        $ssl      = $this->getConfig()->getSsl();
        if ($ssl->getOpen()) {
            $protocol = 'ssl';
        }

        return sprintf('%s://%s:%s', $protocol, $this->host, $this->port);
    }
}
