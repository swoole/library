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

use Swoole\Constant;
use Swoole\Coroutine\FastCGI\Client\Exception;
use Swoole\Coroutine\Socket;
use Swoole\FastCGI;
use Swoole\FastCGI\FrameParser;
use Swoole\FastCGI\HttpRequest;
use Swoole\FastCGI\HttpResponse;
use Swoole\FastCGI\Record\EndRequest;
use Swoole\FastCGI\Request;
use Swoole\FastCGI\Response;

class Client
{
    protected int $af;

    protected string $host;

    protected int $port;

    protected ?Socket $socket = null;

    public function __construct(string $host, int $port = 0, protected bool $ssl = false)
    {
        if (stripos($host, 'unix:/') === 0) {
            $this->af = AF_UNIX;
            $host     = '/' . ltrim(substr($host, strlen('unix:/')), '/');
            $port     = 0;
        } elseif (str_contains($host, ':')) {
            $this->af = AF_INET6;
        } else {
            $this->af = AF_INET;
        }
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * @return ($request is HttpRequest ? HttpResponse : Response)
     * @throws Exception
     */
    public function execute(Request $request, float $timeout = -1): Response
    {
        $sendData = (string) $request;
        if (isset($this->socket) && !$this->socket->checkLiveness()) {
            // The server closed the connection kept open since the last request. A new one is opened before anything
            // is sent, so that the request reaches the server.
            $this->socket->close();
            $this->socket = null;
        }
        if (!isset($this->socket)) {
            $this->socket = $socket = new Socket($this->af, SOCK_STREAM, IPPROTO_IP);
            $socket->setProtocol([
                Constant::OPTION_OPEN_SSL              => $this->ssl,
                Constant::OPTION_OPEN_FASTCGI_PROTOCOL => true,
            ]);
            if (!$socket->connect($this->host, $this->port, $timeout)) {
                $this->ioException();
            }
        } else {
            $socket = $this->socket;
        }
        if ($socket->sendAll($sendData, $timeout) !== strlen($sendData)) {
            $this->ioException();
        }
        $records = [];
        while (true) {
            $recvData = $socket->recvPacket($timeout);
            if (!$recvData) {
                if ($recvData === '') {
                    $this->ioException(SOCKET_ECONNRESET);
                }
                $this->ioException();
            }
            if (!FrameParser::hasFrame($recvData)) {
                $this->ioException(SOCKET_EPROTO);
            }

            try {
                do {
                    $records[] = $record = FrameParser::parseFrame($recvData);
                    if ($record->getRequestId() !== FastCGI::DEFAULT_REQUEST_ID
                        || !in_array($record->getType(), [FastCGI::STDOUT, FastCGI::STDERR, FastCGI::END_REQUEST], true)) {
                        throw new \DomainException('Unexpected FastCGI response record', SOCKET_EPROTO);
                    }
                } while (strlen($recvData) !== 0);
                if ($record instanceof EndRequest) {
                    // @phpstan-ignore argument.type,argument.type
                    $response = ($request instanceof HttpRequest) ? new HttpResponse($records) : new Response($records);
                    if (!$request->getKeepConn()) {
                        $this->socket->close();
                        $this->socket = null;
                    }
                    return $response;
                }
            } catch (\Throwable $e) {
                // The rest of the response is still on the connection, and would be read as the response to the next
                // request sent over it.
                $this->socket->close();
                $this->socket = null;
                throw $e;
            }
        }

        // Code execution should never reach here. However, we still put an exit() statement here for safe purpose.
        exit(1); // @phpstan-ignore deadCode.unreachable
    }

    /**
     * Whether the client holds a connection, which it does after a request that asked to keep it.
     */
    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    public static function parseUrl(string $url): array
    {
        $url  = parse_url($url);
        $host = $url['host'] ?? '';
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $port = $url['port'] ?? 0;
        if (empty($host)) {
            $host = $url['path'] ?? '';
            if (empty($host)) {
                throw new \InvalidArgumentException('Invalid url');
            }
            $host = "unix:/{$host}";
        }
        return [$host, $port];
    }

    public static function call(string $url, string $path, $data = '', float $timeout = -1): string
    {
        $client      = new Client(...static::parseUrl($url));
        $pathInfo    = parse_url($path);
        $path        = $pathInfo['path'] ?? '';
        $root        = dirname($path);
        $scriptName  = '/' . basename($path);
        $documentUri = $scriptName;
        $query       = $pathInfo['query'] ?? '';
        $requestUri  = $query ? "{$documentUri}?{$query}" : $documentUri;
        $request     = new HttpRequest();
        $request->withDocumentRoot($root)
            ->withScriptFilename($path)
            ->withScriptName($documentUri)
            ->withDocumentUri($documentUri)
            ->withRequestUri($requestUri)
            ->withQueryString($query)
            ->withBody($data)
            ->withMethod($request->getContentLength() === 0 ? 'GET' : 'POST')
        ;
        $response = $client->execute($request, $timeout);
        return $response->getBody();
    }

    protected function ioException(?int $errno = null): void
    {
        $socket = $this->socket;
        if ($errno !== null) {
            $socket->errCode = $errno;
            $socket->errMsg  = swoole_strerror($errno);
        }
        $socket->close();
        $this->socket = null;
        throw new Exception($socket->errMsg, $socket->errCode);
    }
}
