<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\FastCGI;

class HttpRequest extends Request
{
    protected $params = [
        'DOCUMENT_ROOT' => "\0", /* must be configured */
        'REQUEST_SCHEME' => 'http',
        'REQUEST_METHOD' => 'GET',
        'SCRIPT_NAME' => '', /* path_info */
        'SCRIPT_FILENAME' => '', /* DOCUMENT_ROOT + SCRIPT_NAME */
        'REQUEST_URI' => '/',
        'DOCUMENT_URI' => '/',
        'QUERY_STRING' => '',
        'CONTENT_TYPE' => 'text/plain',
        'CONTENT_LENGTH' => '0',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'SERVER_SOFTWARE' => 'swoole/' . SWOOLE_VERSION,
        'REMOTE_ADDR' => 'unknown',
        'REMOTE_PORT' => '0',
        'SERVER_ADDR' => 'unknown',
        'SERVER_PORT' => '0',
        'SERVER_NAME' => 'Swoole',
        'REDIRECT_STATUS' => '200',
    ];

    public function getDocumentRoot(): ?string
    {
        return $this->params['DOCUMENT_ROOT'] ?? null;
    }

    public function withDocumentRoot($documentRoot): self
    {
        $this->params['DOCUMENT_ROOT'] = $documentRoot;
        return $this;
    }

    public function withoutDocumentRoot(): void
    {
        unset($this->params['DOCUMENT_ROOT']);
    }

    public function getRequestScheme(): ?string
    {
        return $this->params['REQUEST_SCHEME'] ?? null;
    }

    public function withRequestScheme($requestScheme): self
    {
        $this->params['REQUEST_SCHEME'] = $requestScheme;
        return $this;
    }

    public function withoutRequestScheme(): void
    {
        unset($this->params['REQUEST_SCHEME']);
    }

    public function getRequestMethod(): ?string
    {
        return $this->params['REQUEST_METHOD'] ?? null;
    }

    public function withRequestMethod($requestMethod): self
    {
        $this->params['REQUEST_METHOD'] = $requestMethod;
        return $this;
    }

    public function withoutRequestMethod(): void
    {
        unset($this->params['REQUEST_METHOD']);
    }

    public function getScriptName(): ?string
    {
        return $this->params['SCRIPT_NAME'] ?? null;
    }

    public function withScriptName($scriptName): self
    {
        $this->params['SCRIPT_NAME'] = $scriptName;
        return $this;
    }

    public function withoutScriptName(): void
    {
        unset($this->params['SCRIPT_NAME']);
    }

    public function getScriptFilename(): ?string
    {
        return $this->params['SCRIPT_FILENAME'] ?? null;
    }

    public function withScriptFilename($scriptFilename): self
    {
        $this->params['SCRIPT_FILENAME'] = $scriptFilename;
        return $this;
    }

    public function withoutScriptFilename(): void
    {
        unset($this->params['SCRIPT_FILENAME']);
    }

    public function getRequestUri(): ?string
    {
        return $this->params['REQUEST_URI'] ?? null;
    }

    public function withRequestUri($requestUri): self
    {
        $this->params['REQUEST_URI'] = $requestUri;
        return $this;
    }

    public function withoutRequestUri(): void
    {
        unset($this->params['REQUEST_URI']);
    }

    public function getDocumentUri(): ?string
    {
        return $this->params['DOCUMENT_URI'] ?? null;
    }

    public function withDocumentUri($documentUri): self
    {
        $this->params['DOCUMENT_URI'] = $documentUri;
        return $this;
    }

    public function withoutDocumentUri(): void
    {
        unset($this->params['DOCUMENT_URI']);
    }

    public function getQueryString(): ?string
    {
        return $this->params['QUERY_STRING'] ?? null;
    }

    public function withQueryString($queryString): self
    {
        $this->params['QUERY_STRING'] = $queryString;
        return $this;
    }

    public function withoutQueryString(): void
    {
        unset($this->params['QUERY_STRING']);
    }

    public function getContentType(): ?string
    {
        return $this->params['CONTENT_TYPE'] ?? null;
    }

    public function withContentType($contentType): self
    {
        $this->params['CONTENT_TYPE'] = $contentType;
        return $this;
    }

    public function withoutContentType(): void
    {
        unset($this->params['CONTENT_TYPE']);
    }

    public function getContentLength(): ?string
    {
        return $this->params['CONTENT_LENGTH'] ?? null;
    }

    public function withContentLength($contentLength): self
    {
        $this->params['CONTENT_LENGTH'] = $contentLength;
        return $this;
    }

    public function withoutContentLength(): void
    {
        unset($this->params['CONTENT_LENGTH']);
    }

    public function getGatewayInterface(): ?string
    {
        return $this->params['GATEWAY_INTERFACE'] ?? null;
    }

    public function withGatewayInterface($gatewayInterface): self
    {
        $this->params['GATEWAY_INTERFACE'] = $gatewayInterface;
        return $this;
    }

    public function withoutGatewayInterface(): void
    {
        unset($this->params['GATEWAY_INTERFACE']);
    }

    public function getServerProtocol(): ?string
    {
        return $this->params['SERVER_PROTOCOL'] ?? null;
    }

    public function withServerProtocol($serverProtocol): self
    {
        $this->params['SERVER_PROTOCOL'] = $serverProtocol;
        return $this;
    }

    public function withoutServerProtocol(): void
    {
        unset($this->params['SERVER_PROTOCOL']);
    }

    public function getServerSoftware(): ?string
    {
        return $this->params['SERVER_SOFTWARE'] ?? null;
    }

    public function withServerSoftware($serverSoftware): self
    {
        $this->params['SERVER_SOFTWARE'] = $serverSoftware;
        return $this;
    }

    public function withoutServerSoftware(): void
    {
        unset($this->params['SERVER_SOFTWARE']);
    }

    public function getRemoteAddr(): ?string
    {
        return $this->params['REMOTE_ADDR'] ?? null;
    }

    public function withRemoteAddr($remoteAddr): self
    {
        $this->params['REMOTE_ADDR'] = $remoteAddr;
        return $this;
    }

    public function withoutRemoteAddr(): void
    {
        unset($this->params['REMOTE_ADDR']);
    }

    public function getRemotePort(): ?string
    {
        return $this->params['REMOTE_PORT'] ?? null;
    }

    public function withRemotePort($remotePort): self
    {
        $this->params['REMOTE_PORT'] = $remotePort;
        return $this;
    }

    public function withoutRemotePort(): void
    {
        unset($this->params['REMOTE_PORT']);
    }

    public function getServerAddr(): ?string
    {
        return $this->params['SERVER_ADDR'] ?? null;
    }

    public function withServerAddr($serverAddr): self
    {
        $this->params['SERVER_ADDR'] = $serverAddr;
        return $this;
    }

    public function withoutServerAddr(): void
    {
        unset($this->params['SERVER_ADDR']);
    }

    public function getServerPort(): ?string
    {
        return $this->params['SERVER_PORT'] ?? null;
    }

    public function withServerPort($serverPort): self
    {
        $this->params['SERVER_PORT'] = $serverPort;
        return $this;
    }

    public function withoutServerPort(): void
    {
        unset($this->params['SERVER_PORT']);
    }

    public function getServerName(): ?string
    {
        return $this->params['SERVER_NAME'] ?? null;
    }

    public function withServerName($serverName): self
    {
        $this->params['SERVER_NAME'] = $serverName;
        return $this;
    }

    public function withoutServerName(): void
    {
        unset($this->params['SERVER_NAME']);
    }

    public function getRedirectStatus(): ?string
    {
        return $this->params['REDIRECT_STATUS'] ?? null;
    }

    public function withRedirectStatus($redirectStatus): self
    {
        $this->params['REDIRECT_STATUS'] = $redirectStatus;
        return $this;
    }

    public function withoutRedirectStatus(): void
    {
        unset($this->params['REDIRECT_STATUS']);
    }

    /** @return $this */
    public function withBody(string $body): Message
    {
        parent::withBody($body);
        return $this->withContentLength(strlen($body));
    }
}
