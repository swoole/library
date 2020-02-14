<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine\FastCGI;

use InvalidArgumentException;
use Swoole\Coroutine\FastCGI\Client\Exception;
use Swoole\Coroutine\Socket;
use Swoole\FastCGI\FrameParser;
use Swoole\FastCGI\HttpRequest;
use Swoole\FastCGI\HttpResponse;
use Swoole\FastCGI\Record\EndRequest;
use Swoole\FastCGI\Request;
use Swoole\FastCGI\Response;

class Client
{
    /** @var int */
    protected $af;

    /** @var string */
    protected $host;

    /** @var int */
    protected $port;

    /** @var bool */
    protected $ssl;

    /** @var Socket */
    protected $socket;

    public function __construct(string $host, int $port = 0, bool $ssl = false)
    {
        if (stripos($host, 'unix:/') === 0) {
            $this->af = AF_UNIX;
            $host = '/' . ltrim(substr($host, strlen('unix:/')), '/');
            $port = 0;
        } elseif (strpos($host, ':') !== false) {
            $this->af = AF_INET6;
        } else {
            $this->af = AF_INET;
        }
        $this->host = $host;
        $this->port = $port;
        $this->ssl = $ssl;
    }

    public function execute(Request $request, float $timeout = -1): Response
    {
        if (!$this->socket) {
            $socket = new Socket($this->af, SOCK_STREAM, IPPROTO_IP);
            $socket->setProtocol([
                'open_ssl' => $this->ssl,
                'open_fastcgi_protocol' => true,
            ]);
            if (!$socket->connect($this->host, $this->port, $timeout)) {
                goto _error;
            }
            $this->socket = $socket;
        } else {
            $socket = $this->socket;
        }
        $sendData = (string) $request;
        if ($socket->sendAll($sendData) !== strlen($sendData)) {
            goto _error;
        }
        $records = [];
        while (true) {
            if (SWOOLE_VERSION_ID < 40500) {
                $recvData = '';
                while (true) {
                    $tmp = $socket->recv($timeout);
                    if (!$tmp) {
                        if ($tmp === '') {
                            goto _rst;
                        }
                        goto _error;
                    }
                    $recvData .= $tmp;
                    if (FrameParser::hasFrame($recvData)) {
                        break;
                    }
                }
            } else {
                $recvData = $socket->recvPacket($timeout);
                if (!$recvData) {
                    if ($recvData === '') {
                        goto _rst;
                    }
                    goto _error;
                }
                if (!FrameParser::hasFrame($recvData)) {
                    $socket->errCode = SOCKET_EPROTO;
                    $socket->errMsg = swoole_strerror(SOCKET_EPROTO);
                    goto _error;
                }
            }
            do {
                $records[] = $record = FrameParser::parseFrame($recvData);
            } while (strlen($recvData) !== 0);
            if ($record instanceof EndRequest) {
                if (!$request->getKeepConn()) {
                    $this->socket->close();
                    $this->socket = null;
                }
                switch (true) {
                    case $request instanceof HttpRequest:
                        return new HttpResponse($records);
                    default:
                        return new Response($records);
                }
            }
        }
        _rst:
        $socket->errCode = SOCKET_ECONNRESET;
        $socket->errMsg = swoole_strerror(SOCKET_ECONNRESET);
        _error:
        $socket->close();
        $this->socket = null;
        throw new Exception($socket->errMsg, $socket->errCode);
    }

    public static function call(string $address, string $path, $data = '', float $timeout = -1): string
    {
        $address = parse_url($address);
        $host = $address['host'] ?? '';
        $port = $address['port'] ?? 0;
        if (empty($host)) {
            $host = $address['path'] ?? '';
            if (empty($host)) {
                throw new InvalidArgumentException('Invalid address');
            }
            $host = "unix:/{$host}";
        }
        $client = new Client($host, $port);
        $pathInfo = parse_url($path);
        $path = $pathInfo['path'] ?? '';
        $root = dirname($path);
        $scriptName = '/' . basename($path);
        $documentUri = $scriptName;
        $query = $pathInfo['query'] ?? '';
        $requestUri = $query ? "{$documentUri}?{$query}" : $documentUri;
        $request = new HttpRequest();
        $request->withDocumentRoot($root)
            ->withScriptFilename($path)
            ->withScriptName($documentUri)
            ->withDocumentUri($documentUri)
            ->withRequestUri($requestUri)
            ->withQueryString($query)
            ->withBody($data)
            ->withMethod($request->getContentLength() === 0 ? 'GET' : 'POST');
        $response = $client->execute($request, $timeout);
        return $response->getBody();
    }
}
