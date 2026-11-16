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
 * @throws Exception
 */
function request(
    string $url,
    string $method,
    mixed $data = null,
    ?array $options = null,
    ?array $headers = null,
    ?array $cookies = null,
): ClientProxy {
    $driver = swoole_library_get_option('http_client_driver');
    return match ($driver) {
        'curl'              => request_with_curl($url, $method, $data, $options, $headers, $cookies),
        'stream'            => request_with_stream($url, $method, $data, $options, $headers, $cookies),
        null, 'http_client' => request_with_http_client($url, $method, $data, $options, $headers, $cookies),
        default             => throw new Exception('unknown HTTP client driver: ' . var_export($driver, true)),
    };
}

/**
 * @throws Exception
 */
function request_with_http_client(
    string $url,
    string $method,
    mixed $data = null,
    ?array $options = null,
    ?array $headers = null,
    ?array $cookies = null,
): ClientProxy {
    $info = parse_url($url);
    if (empty($info['scheme'])) {
        throw new Exception('The URL given is illegal [no scheme]');
    }
    if (empty($info['host'])) {
        throw new Exception('The URL given is illegal [no host]');
    }
    if ($info['scheme'] == 'http') {
        $client = new Client($info['host'], swoole_array_default_value($info, 'port', 80), false);
    } elseif ($info['scheme'] == 'https') {
        $client = new Client($info['host'], swoole_array_default_value($info, 'port', 443), true);
    } else {
        throw new Exception('unknown scheme "' . $info['scheme'] . '"');
    }
    $client->setMethod($method);
    if ($data !== null) {
        $client->setData(is_array($data) ? $data : (string) $data);
    }
    $client->set($options ?: []);
    $client->setHeaders($headers ?: []);
    $client->setCookies($cookies ?: []);
    $request_url = swoole_array_default_value($info, 'path', '/');
    if (isset($info['query']) && $info['query'] !== '') {
        $request_url .= '?' . $info['query'];
    }
    if ($client->execute($request_url)) {
        return new ClientProxy(
            $client->getBody(),
            $client->getStatusCode(),
            $client->getHeaders() ?: [],
            $client->getCookies() ?: []
        );
    }
    throw new Exception($client->errMsg, $client->errCode);
}

/**
 * @throws Exception
 */
function request_with_curl(
    string $url,
    string $method,
    mixed $data = null,
    ?array $options = null,
    ?array $headers = null,
    ?array $cookies = null,
): ClientProxy {
    $ch = curl_init($url);
    if (empty($ch)) {
        throw new Exception('failed to curl_init');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    $responseHeaderLines = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $header) use (&$responseHeaderLines): int {
        $responseHeaderLines[] = $header;
        return strlen($header);
    });
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? $data : (string) $data);
    }
    if ($headers !== null) {
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = is_int($key) ? $value : "{$key}: {$value}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
    }
    if ($cookies !== null) {
        $cookie_str = '';
        foreach ($cookies as $k => $v) {
            $cookie_str .= "{$k}={$v}; ";
        }
        curl_setopt($ch, CURLOPT_COOKIE, $cookie_str);
    }
    if (isset($options['timeout'])) {
        if (is_float($options['timeout'])) {
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, intval($options['timeout'] * 1000));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, intval($options['timeout'] * 1000));
        } else {
            curl_setopt($ch, CURLOPT_TIMEOUT, intval($options['timeout']));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, intval($options['timeout']));
        }
    }
    if (isset($options['connect_timeout'])) {
        if (is_float($options['connect_timeout'])) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, intval($options['connect_timeout'] * 1000));
        } else {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, intval($options['connect_timeout']));
        }
    }
    $body = curl_exec($ch);
    if ($body !== false) {
        return ClientProxy::fromHeaders($body, $responseHeaderLines, curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    }
    throw new Exception(curl_error($ch), curl_errno($ch));
}

/**
 * @throws Exception
 */
function request_with_stream(
    string $url,
    string $method,
    mixed $data = null,
    ?array $options = null,
    ?array $headers = null,
    ?array $cookies = null,
): ClientProxy {
    $stream_options = [
        'http' => [
            'method'        => $method,
            'ignore_errors' => true,
        ],
    ];
    $headerStr = '';
    if ($headers !== null) {
        foreach ($headers as $k => $v) {
            $headerStr .= is_int($k) ? $v . "\r\n" : "{$k}: {$v}\r\n";
        }
    }
    if ($cookies !== null && $cookies !== []) {
        $cookieParts = [];
        foreach ($cookies as $k => $v) {
            $cookieParts[] = "{$k}={$v}";
        }
        $headerStr .= 'Cookie: ' . implode('; ', $cookieParts) . "\r\n";
    }
    if (isset($options['timeout'])) {
        $stream_options['http']['timeout'] = (float) $options['timeout'];
    }
    if ($data !== null) {
        if (is_array($data)) {
            $headerStr .= "Content-type: application/x-www-form-urlencoded\r\n";
            $stream_options['http']['content'] = http_build_query($data);
        } else {
            $stream_options['http']['content'] = strval($data);
        }
    }
    if ($headerStr) {
        $stream_options['http']['header'] = $headerStr;
    }
    $http_response_header = [];
    $body                 = @file_get_contents($url, false, stream_context_create($stream_options));
    if ($body !== false) {
        return ClientProxy::fromHeaders($body, $http_response_header);
    }
    $error = error_get_last();
    throw new Exception((string) ($error['message'] ?? 'failed to read HTTP response'));
}

/**
 * @throws Exception
 */
function post(string $url, mixed $data, ?array $options = null, ?array $headers = null, ?array $cookies = null): ClientProxy
{
    return request($url, 'POST', $data, $options, $headers, $cookies);
}

/**
 * @throws Exception
 */
function get(string $url, ?array $options = null, ?array $headers = null, ?array $cookies = null): ClientProxy
{
    return request($url, 'GET', null, $options, $headers, $cookies);
}
