<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Proxy;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Reverse HTTP proxy. Loaded through Composer, not compiled into the extension.
 *
 * The backend address is fixed, so this is not an open forward proxy. The response status,
 * headers and body are passed on. A failed backend becomes 502, and a method this proxy does
 * not forward becomes 405. Backend connections are kept alive and reused through a bounded
 * pool, so a steady request stream does not pay a TCP handshake per request. A pooled
 * connection is dropped once it has been idle for longer than $maxIdleTime, and a request of
 * an idempotent method that meets a connection the backend has closed is sent once more.
 */
final class HttpProxy
{
    private readonly float $timeout;

    /** @var Channel<Client>|null */
    private readonly ?Channel $pool;

    /** @var \WeakMap<Client, float> the time each pooled connection went idle */
    private readonly \WeakMap $idleSince;

    private int $connections = 0;

    public function __construct(
        private readonly string $backendHost,
        private readonly int $backendPort,
        private readonly bool $backendSsl = false,
        float $timeout = 60.0,
        private readonly bool $preserveHost = false,
        private readonly int $maxConnections = 256,
        private readonly float $poolWaitTimeout = 5.0,
        private readonly float $maxIdleTime = 30.0,
    ) {
        if ($backendHost === '' || strlen($backendHost) > 255 || $backendPort < 1 || $backendPort > 65535 || preg_match('/[\x00-\x20]/', $backendHost) === 1) {
            throw new \InvalidArgumentException("Invalid backend address {$backendHost}:{$backendPort}.");
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('Timeout must be positive.');
        }
        if ($poolWaitTimeout <= 0) {
            throw new \InvalidArgumentException('The pool wait timeout must be positive.');
        }
        if ($maxIdleTime < 0) {
            throw new \InvalidArgumentException('The maximum idle time cannot be negative.');
        }
        $this->timeout   = $timeout;
        // A non-positive number of connections turns the pool off and gives each request its own connection.
        $this->pool      = $maxConnections > 0 ? new Channel($maxConnections) : null;
        $this->idleSince = new \WeakMap();
    }

    public function attach(Server $server): void
    {
        $server->handle('/', function (Request $request, Response $response): void {
            $this->forward($request, $response);
        });
    }

    public function forward(Request $request, Response $response): void
    {
        $server = $request->server ?? [];
        $method = strtoupper((string) ($server['request_method'] ?? ''));
        if (!HttpMessage::isMethodAllowed($method)) {
            $response->status(405);
            $response->header('Allow', HttpMessage::ALLOWED_METHODS);
            $response->end('Method Not Allowed');

            return;
        }
        $target = HttpMessage::requestTargetFromServer($server);
        if ($target === null) {
            $response->status(400);
            $response->end('Bad Request');

            return;
        }

        try {
            $client = $this->acquire();
        } catch (\Throwable) {
            $this->badGateway($response);

            return;
        }
        // A client that is already connected came out of the pool; a new one connects on execute().
        $reused  = $client->connected;
        $healthy = false;
        try {
            $remote  = isset($server['remote_addr']) && is_string($server['remote_addr']) ? $server['remote_addr'] : null;
            $headers = HttpMessage::requestHeaders(
                $request->header ?? [],
                $this->backendHost,
                $this->backendPort,
                $this->backendSsl,
                $this->preserveHost,
                $remote,
            );
            $client->set([
                'timeout'            => $this->timeout,
                'http_compression'   => false,
                'body_decompression' => false,
            ]);
            $client->setMethod($method);
            $client->setHeaders($headers);
            $body = $request->getContent();
            if (!is_string($body) || $body === '' || $method === 'GET' || $method === 'HEAD') {
                // A reused client must not send the body of the previous request again.
                $body = null;
            }
            $client->requestBody = $body;
            $ok                  = $client->execute($target);
            if (!$ok && $reused && self::isIdempotent($method) && in_array($client->errCode, [SOCKET_ECONNRESET, SOCKET_EPIPE], true)) {
                // The backend may have closed the connection while it sat idle in the pool. The
                // request of an idempotent method is sent once more, over a new connection.
                $client->close();
                $ok = $client->execute($target);
            }
            if (!$ok || $client->statusCode < 100 || $client->statusCode > 599) {
                $this->badGateway($response);

                return;
            }
            $healthy = true;
            $response->status($client->statusCode);
            $cookies  = is_array($client->set_cookie_headers) ? $client->set_cookie_headers : [];
            $incoming = is_array($client->headers) ? $client->headers : [];
            $fields   = HttpMessage::responseHeaders($incoming, $cookies);
            foreach ($fields['headers'] as $name => $value) {
                $response->header($name, $value, false);
            }
            foreach ($fields['cookies'] as $cookie) {
                $response->header('set-cookie', $cookie, false);
            }
            if ($method === 'HEAD' || $client->statusCode === 204 || $client->statusCode === 304) {
                $response->end();

                return;
            }
            $response->end($client->body);
        } catch (\Throwable) {
            $this->badGateway($response);
        } finally {
            $healthy ? $this->release($client) : $this->discard($client);
        }
    }

    private function acquire(): Client
    {
        if ($this->pool === null) {
            $this->connections++;

            return $this->createClient();
        }
        while (true) {
            // No coroutine can take the client between isEmpty() and pop(), so this pop never waits.
            if (!$this->pool->isEmpty()) {
                $client = $this->pool->pop();
                if ($client instanceof Client) {
                    if ($this->isReusable($client)) {
                        return $client;
                    }
                    continue;
                }
            }

            if ($this->connections < $this->maxConnections) {
                $this->connections++;

                return $this->createClient();
            }

            $client = $this->pool->pop($this->poolWaitTimeout);
            if ($client instanceof Client) {
                if ($this->isReusable($client)) {
                    return $client;
                }
                continue;
            }

            throw new \RuntimeException('Timed out waiting for a free backend connection.');
        }
    }

    private function createClient(): Client
    {
        try {
            return new Client($this->backendHost, $this->backendPort, $this->backendSsl);
        } catch (\Throwable $throwable) {
            $this->connections--;

            throw $throwable;
        }
    }

    /**
     * Whether a client taken from the pool can carry the next request, releasing the slot when it cannot.
     */
    private function isReusable(Client $client): bool
    {
        $idleSince = $this->idleSince[$client] ?? null;
        unset($this->idleSince[$client]);
        if (!$client->connected) {
            $client->close();
            $this->connections--;

            return false;
        }
        if ($idleSince !== null && $this->maxIdleTime > 0 && microtime(true) - $idleSince > $this->maxIdleTime) {
            $client->close();
            $this->connections--;

            return false;
        }

        return true;
    }

    private function release(Client $client): void
    {
        if ($this->pool === null || !$client->connected) {
            $this->discard($client);

            return;
        }
        // The client copies the response body and keeps the request headers and body in PHP
        // properties until it is used again: do not hold them for as long as it sits idle.
        $client->body               = '';
        $client->headers            = null;
        $client->set_cookie_headers = null;
        $client->requestBody        = null;
        $client->requestHeaders     = [];
        $this->idleSince[$client]   = microtime(true);
        $this->pool->push($client);
    }

    private function discard(Client $client): void
    {
        $client->close();
        $this->connections--;
    }

    /**
     * Whether running the request twice has the same effect on the backend as running it once
     * (RFC 9110, section 9.2.2). Only these methods are retried after a connection failure.
     */
    private static function isIdempotent(string $method): bool
    {
        return in_array($method, ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'], true);
    }

    private function badGateway(Response $response): void
    {
        $response->status(502);
        $response->end('Bad Gateway');
    }
}
