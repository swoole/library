<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine\Http;

class ClientProxy
{
    private $body;
    private $statusCode;
    private $headers;
    private $cookies;

    function __construct($body, $statusCode, $headers, $cookies)
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->cookies = $cookies;
    }

    function getBody()
    {
        return $this->body;
    }

    function getStatusCode()
    {
        return $this->statusCode;
    }

    function getHeaders()
    {
        return $this->headers;
    }

    function getCookies()
    {
        return $this->cookies;
    }
}
