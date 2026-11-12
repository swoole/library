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

use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Reverse HTTP proxy. Loaded through Composer, not compiled into the extension.
 *
 * The backend address is fixed, so this is not an open forward proxy. The response status,
 * headers and body are passed on. A failed backend becomes 502, and a method this proxy does
 * not forward becomes 405. Each request uses its own connection and closes it afterwards, so a
 * failed request cannot be reused and a recycled connection id cannot keep a backend socket.
 */
final class HttpProxy
{
    private readonly float $timeout;

    public function __construct(
        private readonly string $backendHost,
        private readonly int $backendPort,
        private readonly bool $backendSsl = false,
        float $timeout = 60.0,
        private readonly bool $preserveHost = false,
    ) {
        if ($backendHost === '' || strlen($backendHost) > 255 || $backendPort < 1 || $backendPort > 65535 || preg_match('/[\x00-\x20]/', $backendHost) === 1) {
            throw new \InvalidArgumentException("Invalid backend address {$backendHost}:{$backendPort}.");
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('Timeout must be positive.');
        }
        $this->timeout = $timeout;
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

        $client = new Client($this->backendHost, $this->backendPort, $this->backendSsl);
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
            if (is_string($body) && $body !== '' && $method !== 'GET' && $method !== 'HEAD') {
                $client->setData($body);
            }
            $ok = $client->execute($target);
            if (!$ok || $client->statusCode < 100 || $client->statusCode > 599) {
                $this->badGateway($response);

                return;
            }
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
            $client->close();
        }
    }

    private function badGateway(Response $response): void
    {
        $response->status(502);
        $response->end('Bad Gateway');
    }
}
