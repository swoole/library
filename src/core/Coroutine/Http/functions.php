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

use Swoole\Coroutine\Http\Client\Exception;

/**
 * @param string $url
 * @param string $method
 * @param null $data
 * @param array|null $options
 * @param array|null $headers
 * @param array|null $cookies
 * @return false|ClientProxy
 * @throws Exception
 */
function request(string $url, string $method, $data = null, array $options = null, array $headers = null, array $cookies = null)
{
    if (swoole_library_get_option('http_client_driver') == 'curl') {
        return request_with_curl($url, $method, $data, $options, $headers, $cookies);
    } else {
        return request_with_http_client($url, $method, $data, $options, $headers, $cookies);
    }
}

/**
 * @param mixed $data
 * @return ClientProxy|false
 * @throws Exception
 */
function request_with_http_client(string $url, string $method, $data = null, array $options = null, array $headers = null, array $cookies = null)
{
    $info = parse_url($url);
    if (empty($info['scheme'])) {
        throw new Exception('The URL given is illegal [no scheme]');
    }
    if ($info['scheme'] == 'http') {
        $client = new Client($info['host'], swoole_array_default_value($info, 'port', 80), false);
    } elseif ($info['scheme'] == 'https') {
        $client = new Client($info['host'], swoole_array_default_value($info, 'port', 443), true);
    } else {
        throw new Exception('unknown scheme "' . $info['scheme'] . '"');
    }
    $client->setMethod($method);
    if ($data) {
        $client->setData($data);
    }
    if (is_array($options)) {
        $client->set($options);
    }
    if (is_array($headers)) {
        $client->setHeaders($headers);
    }
    if (is_array($cookies)) {
        $client->setCookies($cookies);
    }
    $request_url = swoole_array_default_value($info, 'path', '/');
    if (!empty($info['query'])) {
        $request_url .= '?' . $info['query'];
    }
    if ($client->execute($request_url)) {
        return new ClientProxy($client->getBody(), $client->getStatusCode(), $client->getHeaders(), $client->getCookies());
    }
    return false;
}

/**
 * @param mixed $data
 * @return void
 * @throws Exception
 */
function request_with_curl(string $url, string $method, $data = null, array $options = null, array $headers = null, array $cookies = null)
{
    $ch = curl_init($url);
    if (empty($ch)) {
        throw new Exception('failed to curl_init');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    $responseHeaders = $responseCookies = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
        $len = strlen($header);
        $header = explode(':', $header, 2);
        if (count($header) < 2) {
            return $len;
        }
        $headerKey = strtolower(trim($header[0]));
        if ($headerKey == 'set-cookie') {
            [$k, $v] = explode('=', $header[1]);
            $responseCookies[$k] = $v;
        } else {
            $responseHeaders[$headerKey][] = trim($header[1]);
        }
        return $len;
    });
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($cookies) {
        $cookie_str = '';
        foreach ($cookies as $k => $v) {
            $cookie_str .= "$k=$v; ";
        }
        curl_setopt($ch, CURLOPT_COOKIE, $cookie_str);
    }
    $body = curl_exec($ch);
    if ($body) {
        return new ClientProxy($body, curl_getinfo($ch, CURLINFO_HTTP_CODE), $responseHeaders, $responseCookies);
    } else {
        return false;
    }
}


/**
 * @param mixed $data
 * @throws Exception
 * @return Client|false|mixed
 */
function post(string $url, $data, array $options = null, array $headers = null, array $cookies = null)
{
    return request($url, 'POST', $data, $options, $headers, $cookies);
}

/**
 * @throws Exception
 * @return Client|false|mixed
 */
function get(string $url, array $options = null, array $headers = null, array $cookies = null)
{
    return request($url, 'GET', null, $options, $headers, $cookies);
}
