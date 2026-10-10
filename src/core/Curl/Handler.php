<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

/* @noinspection PhpComposerExtensionStubsInspection, PhpDuplicateSwitchCaseBodyInspection, PhpInconsistentReturnPointsInspection */

declare(strict_types=1);

namespace Swoole\Curl;

use Swoole\Constant;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\System;
use Swoole\Curl\Exception as CurlException;
use Swoole\Http\Status;
use Swoole\Timer;

final class Handler implements \Stringable
{
    private const DEFAULT_INFO = [
        'url'                     => '',
        'content_type'            => null,
        'http_code'               => 0,
        'header_size'             => 0,
        'request_size'            => 0,
        'filetime'                => -1,
        'ssl_verify_result'       => 0,
        'redirect_count'          => 0,
        'total_time'              => 0.0,
        'namelookup_time'         => 0.0,
        'connect_time'            => 0.0,
        'pretransfer_time'        => 0.0,
        'size_upload'             => 0.0,
        'size_download'           => 0.0,
        'speed_download'          => 0.0,
        'speed_upload'            => 0.0,
        'download_content_length' => -1.0,
        'upload_content_length'   => -1.0,
        'starttransfer_time'      => 0.0,
        'redirect_time'           => 0.0,
        'redirect_url'            => '',
        'primary_ip'              => '',
        'certinfo'                => [],
        'primary_port'            => 0,
        'local_ip'                => '',
        'local_port'              => 0,
        'http_version'            => 0,
        'protocol'                => 0,
        'ssl_verifyresult'        => 0,
        'scheme'                  => '',
        'private'                 => '',
        'appconnect_time'         => 0.0,
    ];

    private ?Client $client = null;

    private array $info = self::DEFAULT_INFO;

    private string $url = '';

    private int $port = 0;

    private bool $withHeaderOut = false;

    private bool $withFileTime = false;

    private ?array $urlInfo = null;

    private array $origin = [];

    private int $protocols = CURLPROTO_HTTP | CURLPROTO_HTTPS;

    private int $redirectProtocols = CURLPROTO_HTTP | CURLPROTO_HTTPS;

    private ?string $cookie = null;

    private mixed $postData = null;

    /** @var resource|null */
    private mixed $infile = null;

    private int $infileSize = PHP_INT_MAX;

    /** @var resource|null */
    private mixed $outputStream = null;

    private int $proxyType = CURLPROXY_HTTP;

    private ?string $proxy = null;

    private int $proxyPort = 1080;

    private ?string $proxyUsername = null;

    private ?string $proxyPassword = null;

    /**
     * The ways to authenticate that CURLOPT_HTTPAUTH and CURLOPT_PROXYAUTH allow. The handler knows the basic way
     * only, which is the default of both options.
     */
    private int $httpAuth = CURLAUTH_BASIC;

    private int $proxyAuth = CURLAUTH_BASIC;

    private bool $hasUserPassword = false;

    private array $clientOptions = ['ssl_verify_peer' => true, 'ssl_verify_host' => true, 'collect_stats' => true];

    private bool $followLocation = false;

    private bool $autoReferer = false;

    // Match PHP's cURL handle default, independently of libcurl's default.
    private int $maxRedirects = 20;

    private bool $withHeader = false;

    private bool $nobody = false;

    /** @var callable|null */
    private mixed $headerFunction = null;

    /** @var callable|null */
    private mixed $readFunction = null;

    private bool $writeError = false;

    /** @var callable|null */
    private mixed $writeFunction = null;

    private bool $noProgress = true;

    /** @var callable|null */
    private mixed $progressFunction = null;

    /** @var callable|null */
    private mixed $prereqFunction = null;

    private bool $returnTransfer = false;

    private string $method = '';

    private string $customRequest = '';

    private array $headers = [];

    private array $headerMap = [];

    private array $customHeaders = [];

    private ?string $transfer = null;

    private int $errCode = 0;

    private string $errMsg = '';

    private bool $failOnError = false;

    private bool $closed = false;

    private string $cookieJar = '';

    private array $resolve = [];

    /**
     * The addresses CURLOPT_RESOLVE gives for the host of the client, all of them, and those not tried yet.
     *
     * @var array<string>
     */
    private array $resolveAll = [];

    /**
     * @var array<string>
     */
    private array $resolveNext = [];

    private string $unix_socket_path = '';

    public function __construct(string $url = '')
    {
        if ($url) {
            $this->setUrl($url);
        }
    }

    public function __toString(): string
    {
        $id = spl_object_id($this);
        return "Object({$id}) of type (curl)";
    }

    /* ====== Public APIs ====== */

    public function isAvailable(): bool
    {
        if ($this->closed) {
            trigger_error('supplied resource is not a valid cURL handle resource', E_USER_WARNING);
            return false;
        }
        return true;
    }

    public function setOpt(int $opt, mixed $value): bool
    {
        return $this->isAvailable() && $this->setOption($opt, $value);
    }

    public function exec(): string|bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        return $this->execute();
    }

    public function getInfo(): array|false
    {
        return $this->isAvailable() ? $this->info : false;
    }

    public function errno(): int
    {
        return $this->isAvailable() ? $this->errCode : 0;
    }

    public function error(): string
    {
        return $this->isAvailable() ? $this->errMsg : '';
    }

    public function reset(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        foreach ((new \ReflectionClass(self::class))->getDefaultProperties() as $name => $value) {
            $this->{$name} = $value;
        }
        return true;
    }

    public function getContent(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }
        return $this->transfer;
    }

    public function close(): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        /* Release all references (connections, streams, callbacks) by restoring default property values. */
        foreach ((new \ReflectionClass(self::class))->getDefaultProperties() as $name => $value) {
            $this->{$name} = $value;
        }
        $this->closed = true;
    }

    private function create(?array $urlInfo = null): void
    {
        if ($urlInfo === null) {
            $urlInfo = $this->urlInfo;
        }
        $host              = $urlInfo['host'];
        $port              = $urlInfo['port'];
        $this->resolveAll  = [];
        $this->resolveNext = [];
        if (isset($this->resolve[$host])) {
            $this->setHeader('Host', $host);
            $addresses = $this->resolve[$host][$port] ?? [];
            if ($addresses) {
                $this->resolveAll  = $addresses;
                $host              = array_shift($addresses);
                $this->resolveNext = $addresses;
            }
            $this->urlInfo['host'] = $host;
        }
        if ($this->unix_socket_path) {
            $host = $this->unix_socket_path;
            $port = 0;
            if (stripos($host, 'unix:/') !== 0) {
                $host = "unix:/{$host}";
            }
        }
        $this->client = new Client($host, $port, $urlInfo['scheme'] === 'https');
    }

    /**
     * Replaces the client by one for the next address of CURLOPT_RESOLVE, carrying over the request of the current
     * client.
     *
     * @param float|null $connectTimeout the time to connect to the address, when it differs from the one set
     */
    private function useNextAddress(Client $client, ?float $connectTimeout = null): Client
    {
        $this->urlInfo['host'] = $address = (string) array_shift($this->resolveNext);

        $next     = new Client($address, $client->port, $client->ssl);
        $settings = $client->setting ?? [];
        if ($connectTimeout !== null) {
            $settings[Constant::OPTION_CONNECT_TIMEOUT] = $connectTimeout;
        }
        if ($settings) {
            $next->set($settings);
        }
        if ($client->requestMethod) {
            $next->setMethod($client->requestMethod);
        }
        $next->setHeaders($client->requestHeaders ?? []);
        // The handler sends its cookies as a header. An empty list would be sent as an empty Cookie header.
        if ($client->cookies) {
            $next->setCookies($client->cookies);
        }
        if ($client->requestBody !== null) {
            $next->setData($client->requestBody);
        }
        foreach ($client->uploadFiles ?? [] as $file) {
            $next->addFile($file['path'], $file['name'], $file['type'], $file['filename'], $file['offset'], $file['size']);
        }

        return $this->client = $next;
    }

    private function getUrl(): string
    {
        if (empty($this->urlInfo['path'])) {
            $url = '/';
        } else {
            $url = $this->urlInfo['path'];
        }
        if (isset($this->urlInfo['query']) && $this->urlInfo['query'] !== '') {
            $url .= '?' . $this->urlInfo['query'];
        }
        return $url;
    }

    private function setUrl(string $url, bool $setInfo = true): bool
    {
        if (strlen($url) === 0) {
            $this->setError(CURLE_URL_MALFORMAT, 'No URL set!');
            return false;
        }
        if (!str_contains($url, '://') && $this->unix_socket_path === '') {
            $url = 'http://' . $url;
        }
        if ($setInfo) {
            $urlInfo = parse_url($url);
            if ($this->unix_socket_path) {
                if (empty($urlInfo['host']) && !empty($urlInfo['path'])) {
                    $urlInfo['host'] = explode('/', $urlInfo['path'])[1] ?? null;
                }
                if (!empty($urlInfo['host'])) {
                    $this->setHeader('Host', $urlInfo['host']);
                }
            }
            if (!is_array($urlInfo)) {
                $this->setError(CURLE_URL_MALFORMAT, "URL[{$url}] using bad/illegal format");
                return false;
            }
            if (!$this->setUrlInfo($urlInfo)) {
                return false;
            }
            $this->origin = $urlInfo;
            $this->url    = $url;
        }
        $this->info['url'] = $url;
        return true;
    }

    private function setUrlInfo(array $urlInfo): bool
    {
        if (empty($urlInfo['scheme'])) {
            $urlInfo['scheme'] = 'http';
        }
        $scheme = $urlInfo['scheme'];
        if ($scheme !== 'http' && $scheme !== 'https') {
            $this->setError(CURLE_UNSUPPORTED_PROTOCOL, "Protocol \"{$scheme}\" not supported or disabled in libcurl");
            return false;
        }
        $host = $urlInfo['host'];
        if ($this->port !== 0) {
            // CURLOPT_PORT overrides the port in the URL; connection statistics never do.
            $urlInfo['port'] = $this->port;
        } elseif (empty($urlInfo['port'])) {
            $urlInfo['port'] = $scheme === 'https' ? 443 : 80;
        } else {
            $urlInfo['port'] = intval($urlInfo['port']);
        }
        $port = $urlInfo['port'];
        if (isset($this->client)) {
            $oldUrlInfo = $this->urlInfo;
            if (($host !== $oldUrlInfo['host']) || ($port !== $oldUrlInfo['port']) || ($scheme !== $oldUrlInfo['scheme'])) {
                /* target changed */
                $this->create($urlInfo);
            }
        }
        $this->urlInfo = $urlInfo;
        return true;
    }

    private function setPort(int $port): void
    {
        if ($this->port !== $port) {
            $this->port   = $port;
            $this->client = null;
        }
    }

    private function checkProtocol(string $scheme, bool $redirect = false): bool
    {
        $protocol = match (strtolower($scheme)) {
            'http'  => CURLPROTO_HTTP,
            'https' => CURLPROTO_HTTPS,
            default => 0,
        };
        $allowed = $this->protocols;
        if ($redirect) {
            $allowed &= $this->redirectProtocols;
        }
        if (($protocol & $allowed) === 0) {
            $this->setError(CURLE_UNSUPPORTED_PROTOCOL, "Protocol \"{$scheme}\" not supported or disabled in libcurl");
            return false;
        }
        return true;
    }

    private function getOrigin(array $urlInfo): array
    {
        $scheme = strtolower($urlInfo['scheme'] ?? 'http');
        return [
            $scheme,
            strtolower($urlInfo['host']),
            $this->port ?: ($urlInfo['port'] ?? ($scheme === 'https' ? 443 : 80)),
        ];
    }

    private function getRequestHeaders(): array
    {
        // Compare URL origins, not connection addresses substituted by CURLOPT_RESOLVE.
        $sameOrigin = $this->getOrigin(parse_url($this->info['url'])) === $this->getOrigin($this->origin);
        $headers    = $this->headers;
        foreach ($this->customHeaders as $lowerCaseName => [$name, $value]) {
            if (isset($this->headerMap[$lowerCaseName])) {
                unset($headers[$this->headerMap[$lowerCaseName]]);
            }
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }
        $hasCookie = $sameOrigin && isset($this->customHeaders['cookie']);
        foreach ($headers as $name => $value) {
            $nameLower = strtolower($name);
            if (!$sameOrigin && ($nameLower === 'authorization' || $nameLower === 'cookie')) {
                unset($headers[$name]);
            } elseif ($nameLower === 'cookie') {
                $hasCookie = true;
            }
        }
        // Unlike a custom Cookie header, CURLOPT_COOKIE applies to every redirect target.
        if (!$hasCookie && $this->cookie !== null && $this->cookie !== '') {
            $headers['Cookie'] = $this->cookie;
        }
        return $headers;
    }

    private function setError(int $code, string $msg = ''): void
    {
        $this->errCode = $code;
        $this->errMsg  = $code === CURLE_OK ? '' : ($msg ?: (curl_strerror($code) ?? ''));
    }

    private function hasHeader(string $headerName): bool
    {
        $name = strtolower($headerName);
        return isset($this->customHeaders[$name]) || isset($this->headerMap[$name]);
    }

    private function setHeader(string $headerName, string $value): void
    {
        $lowerCaseHeaderName = strtolower($headerName);

        if (isset($this->headerMap[$lowerCaseHeaderName])) {
            unset($this->headers[$this->headerMap[$lowerCaseHeaderName]]);
        }

        if ($value !== '') {
            $this->headers[$headerName]            = $value;
            $this->headerMap[$lowerCaseHeaderName] = $headerName;
        } else {
            // remove empty headers (keep same with raw cURL)
            unset($this->headerMap[$lowerCaseHeaderName]);
        }
    }

    /**
     * @throws Exception
     */
    private function setOption(int $opt, mixed $value): bool
    {
        switch ($opt) {
            // case CURLOPT_STDERR:
            // case CURLOPT_WRITEHEADER:
            case CURLOPT_FILE:
            case CURLOPT_INFILE:
                if (!is_resource($value)) {
                    trigger_error('swoole_curl_setopt(): supplied argument is not a valid File-Handle resource', E_USER_WARNING);
                    return false;
                }
                break;
        }

        switch ($opt) {
            /*
             * Basic
             */
            case CURLOPT_URL:
                return $this->setUrl((string) $value);
            case CURLOPT_PORT:
                $this->setPort((int) $value);
                break;
            case CURLOPT_FORBID_REUSE:
                $this->clientOptions[Constant::OPTION_KEEP_ALIVE] = !$value;
                break;
            case CURLOPT_RETURNTRANSFER:
                if ($this->writeFunction !== null) {
                    $this->client        = null;
                    $this->writeFunction = null;
                    unset($this->clientOptions[Constant::OPTION_WRITE_FUNC]);
                }
                $this->returnTransfer = (bool) $value;
                $this->transfer       = '';
                break;
            case CURLOPT_ENCODING:
                if (empty($value)) {
                    if (defined('SWOOLE_HAVE_ZLIB')) {
                        $value = 'gzip, deflate';
                    }
                    if (defined('SWOOLE_HAVE_BROTLI')) {
                        if (!empty($value)) {
                            $value = 'br, ' . $value;
                        } else {
                            $value = 'br';
                        }
                    }
                    if (empty($value)) {
                        break;
                    }
                }
                $this->setHeader('Accept-Encoding', $value);
                break;
            case CURLOPT_PROXYTYPE:
                if ($value !== CURLPROXY_HTTP && $value !== CURLPROXY_SOCKS5) {
                    throw new CurlException('swoole_curl_setopt(): Only support following CURLOPT_PROXYTYPE values: CURLPROXY_HTTP, CURLPROXY_SOCKS5');
                }
                if ($this->proxyType !== $value) {
                    $this->client = null;
                }
                $this->proxyType = $value;
                break;
            case CURLOPT_PROXY:
                if ($this->proxy !== (string) $value) {
                    $this->client = null;
                }
                $this->proxy = (string) $value;
                break;
            case CURLOPT_PROXYPORT:
                if ($this->proxyPort !== (int) $value) {
                    $this->client = null;
                }
                $this->proxyPort = (int) $value;
                break;
            case CURLOPT_PROXYUSERNAME:
                $this->client        = null;
                $this->proxyUsername = (string) $value;
                break;
            case CURLOPT_PROXYPASSWORD:
                $this->client        = null;
                $this->proxyPassword = (string) $value;
                break;
            case CURLOPT_PROXYUSERPWD:
                $this->client        = null;
                $usernamePassword    = explode(':', (string) $value);
                $this->proxyUsername = urldecode($usernamePassword[0]);
                $this->proxyPassword = urldecode((string) ($usernamePassword[1] ?? null));
                break;
            case CURLOPT_PROXYAUTH:
                // Checked in execute(), where it is known whether there is a password to send.
                $this->proxyAuth = (int) $value;
                break;
            case CURLOPT_UNIX_SOCKET_PATH:
                $realpath = realpath((string) $value);
                if ($realpath) {
                    $this->unix_socket_path = $realpath;
                } else {
                    $this->setError(CURLE_COULDNT_CONNECT);
                }
                break;
            case CURLOPT_NOBODY:
                $this->nobody = boolval($value);
                if ($this->nobody) {
                    $this->method = 'HEAD';
                } elseif ($this->method === 'HEAD') {
                    $this->method = 'GET';
                }
                break;
            case CURLOPT_RESOLVE:
                foreach ((array) $value as $resolve) {
                    $flag = substr((string) $resolve, 0, 1);
                    if ($flag === '+' || $flag === '-') {
                        // In libcurl a "+" entry expires like a regular DNS cache entry. There is no DNS cache
                        // here, so it is handled like a plain HOST:PORT:ADDRESS entry, on purpose.
                        $resolve = substr((string) $resolve, 1);
                    }
                    $tmpResolve = explode(':', (string) $resolve, 3);
                    $host       = $tmpResolve[0];
                    $port       = $tmpResolve[1] ?? 0;
                    $ip         = $tmpResolve[2] ?? '';
                    if ($flag === '-') {
                        unset($this->resolve[$host][$port]);
                    } else {
                        // HOST:PORT:ADDRESS[,ADDRESS]...: the addresses are tried in turn until one can be connected to.
                        // An IPv6 address may come in brackets, as in [::1].
                        $addresses                   = array_map(static fn (string $address): string => trim($address, " \t[]"), explode(',', $ip));
                        $this->resolve[$host][$port] = array_values(array_filter($addresses, static fn (string $address): bool => $address !== ''));
                    }
                }
                break;
            case CURLOPT_IPRESOLVE:
                if ($value !== CURL_IPRESOLVE_WHATEVER && $value !== CURL_IPRESOLVE_V4) {
                    throw new CurlException('swoole_curl_setopt(): Only support following CURLOPT_IPRESOLVE values: CURL_IPRESOLVE_WHATEVER, CURL_IPRESOLVE_V4');
                }
                break;
            case CURLOPT_TCP_NODELAY:
                $this->clientOptions[Constant::OPTION_OPEN_TCP_NODELAY] = boolval($value);
                break;
            case CURLOPT_PRIVATE:
                $this->info['private'] = $value;
                break;
                /*
                 * Ignore options
                 */
            case CURLOPT_VERBOSE:
                // trigger_error('swoole_curl_setopt(): CURLOPT_VERBOSE is not supported', E_USER_WARNING);
            case CURLOPT_SSLVERSION:
            case CURLOPT_NOSIGNAL:
            case CURLOPT_FRESH_CONNECT:
            case CURLOPT_DNS_USE_GLOBAL_CACHE:
            case CURLOPT_DNS_CACHE_TIMEOUT:
            case CURLOPT_STDERR:
            case CURLOPT_WRITEHEADER:
            case CURLOPT_BUFFERSIZE:
            case CURLOPT_SSLCERTTYPE:
            case CURLOPT_SSLKEYTYPE:
            case CURLOPT_NOPROXY:
            case CURLOPT_CERTINFO:
            case CURLOPT_HEADEROPT:
            case CURLOPT_PROXYHEADER:
            case CURLOPT_HTTPPROXYTUNNEL:
                break;
                /*
                 * SSL
                 */
            case CURLOPT_SSL_VERIFYHOST:
                $this->client                           = null;
                $this->clientOptions['ssl_verify_host'] = (bool) $value;
                break;
            case CURLOPT_SSL_VERIFYPEER:
                $this->client                                          = null;
                $this->clientOptions[Constant::OPTION_SSL_VERIFY_PEER] = $value;
                break;
            case CURLOPT_SSLCERT:
                $this->client                                        = null;
                $this->clientOptions[Constant::OPTION_SSL_CERT_FILE] = $value;
                break;
            case CURLOPT_SSLKEY:
                $this->client                                       = null;
                $this->clientOptions[Constant::OPTION_SSL_KEY_FILE] = $value;
                break;
            case CURLOPT_CAINFO:
                $this->client                                     = null;
                $this->clientOptions[Constant::OPTION_SSL_CAFILE] = $value;
                break;
            case CURLOPT_CAPATH:
                $this->client                                     = null;
                $this->clientOptions[Constant::OPTION_SSL_CAPATH] = $value;
                break;
            case CURLOPT_KEYPASSWD:
            case CURLOPT_SSLCERTPASSWD:
            case CURLOPT_SSLKEYPASSWD:
                $this->client                                         = null;
                $this->clientOptions[Constant::OPTION_SSL_PASSPHRASE] = $value;
                break;
                /*
                 * Http POST
                 */
            case CURLOPT_POST:
                $this->method = $value ? 'POST' : 'GET';
                if ($value) {
                    $this->nobody = false;
                }
                break;
            case CURLOPT_POSTFIELDS:
                $this->postData = $value;
                $this->method   = 'POST';
                break;
                /*
                 * Upload
                 */
            case CURLOPT_SAFE_UPLOAD:
                if (!$value) {
                    trigger_error('swoole_curl_setopt(): Disabling safe uploads is no longer supported', E_USER_WARNING);
                    return false;
                }
                break;
                /*
                 * Http Header
                 */
            case CURLOPT_HTTPHEADER:
                if (!is_array($value) && !is_iterable($value)) {
                    trigger_error('swoole_curl_setopt(): You must pass either an object or an array with the CURLOPT_HTTPHEADER argument', E_USER_WARNING);
                    return false;
                }
                $headers = [];
                foreach ($value as $header) {
                    $header                           = explode(':', (string) $header, 2);
                    $headerName                       = $header[0];
                    $headerValue                      = trim($header[1] ?? '');
                    $headers[strtolower($headerName)] = [$headerName, $headerValue];
                }
                $this->customHeaders = $headers;
                break;
            case CURLOPT_REFERER:
                $this->setHeader('Referer', $value);
                break;
            case CURLINFO_HEADER_OUT:
                $this->withHeaderOut = boolval($value);
                break;
            case CURLOPT_FILETIME:
                $this->withFileTime = boolval($value);
                break;
            case CURLOPT_USERAGENT:
                $this->setHeader('User-Agent', $value);
                break;
            case CURLOPT_CUSTOMREQUEST:
                $this->customRequest = (string) $value;
                break;
            case CURLOPT_PROTOCOLS:
                if (($value & ~(CURLPROTO_HTTP | CURLPROTO_HTTPS)) != 0) {
                    throw new CurlException("swoole_curl_setopt(): CURLOPT_PROTOCOLS[{$value}] is not supported");
                }
                $this->protocols = (int) $value;
                break;
            case CURLOPT_REDIR_PROTOCOLS:
                if (($value & ~(CURLPROTO_HTTP | CURLPROTO_HTTPS)) != 0) {
                    throw new CurlException("swoole_curl_setopt(): CURLOPT_REDIR_PROTOCOLS[{$value}] is not supported");
                }
                $this->redirectProtocols = (int) $value;
                break;
            case CURLOPT_HTTP_VERSION:
                if ($value != CURL_HTTP_VERSION_1_1) {
                    trigger_error("swoole_curl_setopt(): CURLOPT_HTTP_VERSION[{$value}] is not supported", E_USER_WARNING);
                    return false;
                }
                break;
            case CURLOPT_FAILONERROR:
                $this->failOnError = (bool) $value;
                break;
                /*
                 * Http Cookie
                 */
            case CURLOPT_COOKIE:
                $this->cookie = $value === null ? null : (string) $value;
                break;
            case CURLOPT_COOKIEJAR:
                $this->cookieJar = (string) $value;
                break;
            case CURLOPT_COOKIEFILE:
                if (is_file((string) $value)) {
                    $this->setHeader('Cookie', file_get_contents($value));
                }
                break;
            case CURLOPT_CONNECTTIMEOUT:
                $this->clientOptions[Constant::OPTION_CONNECT_TIMEOUT] = $value;
                break;
            case CURLOPT_CONNECTTIMEOUT_MS:
                $this->clientOptions[Constant::OPTION_CONNECT_TIMEOUT] = $value / 1000;
                break;
            case CURLOPT_TIMEOUT:
                $this->clientOptions[Constant::OPTION_TIMEOUT] = $value;
                break;
            case CURLOPT_TIMEOUT_MS:
                $this->clientOptions[Constant::OPTION_TIMEOUT] = $value / 1000;
                break;
            case CURLOPT_FILE:
                $this->outputStream = $value;
                break;
            case CURLOPT_HEADER:
                $this->withHeader = (bool) $value;
                break;
            case CURLOPT_HEADERFUNCTION:
                $this->headerFunction = $value;
                break;
            case CURLOPT_READFUNCTION:
                $this->readFunction = $value;
                break;
            case CURLOPT_WRITEFUNCTION:
                $this->writeFunction                              = $value;
                $this->clientOptions[Constant::OPTION_WRITE_FUNC] = function ($client, $data) use ($value): bool {
                    if (($this->followLocation && $client->statusCode >= 300 && $client->statusCode < 400
                        && isset($client->headers['location'])) || ($this->failOnError && $client->statusCode >= 400)) {
                        return true;
                    }
                    if ((int) $value($this, $data) !== strlen($data)) {
                        $this->writeError           = true;
                        $this->info['http_code']    = $client->statusCode;
                        $this->info['content_type'] = $client->headers['content-type'] ?? '';
                        return false;
                    }
                    return true;
                };
                break;
            case CURLOPT_NOPROGRESS:
                $this->noProgress = (bool) $value;
                break;
            case CURLOPT_PROGRESSFUNCTION:
                $this->progressFunction = $value;
                break;
            case CURLOPT_PREREQFUNCTION:
                $this->prereqFunction = $value;
                break;
            case CURLOPT_HTTPAUTH:
                // Checked in execute(), where it is known whether there is a password to send.
                $this->httpAuth = (int) $value;
                break;
            case CURLOPT_USERPWD:
                $this->hasUserPassword = true;
                $this->setHeader('Authorization', 'Basic ' . base64_encode($value));
                break;
            case CURLOPT_FOLLOWLOCATION:
                $this->followLocation = (bool) $value;
                break;
            case CURLOPT_AUTOREFERER:
                $this->autoReferer = (bool) $value;
                break;
            case CURLOPT_MAXREDIRS:
                $maxRedirects = (int) $value;
                if ($maxRedirects < -1) {
                    $this->setError(CURLE_BAD_FUNCTION_ARGUMENT);
                    return false;
                }
                $this->maxRedirects = $maxRedirects;
                break;
            case CURLOPT_PUT:
            case CURLOPT_UPLOAD:
                /* after libcurl 7.12, CURLOPT_PUT is replaced by CURLOPT_UPLOAD */
                $this->method = $value ? 'PUT' : 'GET';
                if ($value) {
                    $this->nobody = false;
                }
                break;
            case CURLOPT_INFILE:
                $this->infile = $value;
                break;
            case CURLOPT_INFILESIZE:
                $this->infileSize = (int) $value;
                break;
            case CURLOPT_HTTPGET:
                /* Since GET is the default, this is only necessary if the request method has been changed. */
                if ($value) {
                    $this->method = 'GET';
                    $this->nobody = false;
                }
                break;
            default:
                throw new CurlException("swoole_curl_setopt(): option[{$opt}] is not supported");
        }
        return true;
    }

    private function execute(): string|bool
    {
        $this->setError(CURLE_OK, '');
        $this->writeError             = false;
        $private                      = $this->info['private'];
        $this->info                   = self::DEFAULT_INFO;
        $this->info['private']        = $private;
        $this->transfer               = null;
        if ($this->url !== '' && !$this->setUrl($this->url)) {
            return false;
        }
        $timeBegin                    = microtime(true);
        $timeout                      = (float) ($this->clientOptions[Constant::OPTION_TIMEOUT] ?? 0);
        $deadline                     = $timeout > 0 ? hrtime(true) / 1e9 + $timeout : null;
        $timedOut                     = false;
        /*
         * Socket
         */
        if (!$this->urlInfo) {
            $this->setError(CURLE_URL_MALFORMAT, 'No URL set or URL using bad/illegal format');
            return false;
        }
        if (!$this->checkProtocol($this->urlInfo['scheme'])) {
            return false;
        }
        // The user name and the password are sent the basic way, which is all the client can do. Whoever excludes
        // that way, to keep the password from being sent as it is, must not get it sent anyway.
        if ($this->hasUserPassword && !($this->httpAuth & CURLAUTH_BASIC)) {
            throw new CurlException('swoole_curl_exec(): CURLOPT_USERPWD can only be sent with a CURLOPT_HTTPAUTH value that includes CURLAUTH_BASIC');
        }
        if (!isset($this->client)) {
            $this->create();
        }
        $transferFailed   = false;
        $method           = $this->nobody ? 'HEAD' : ($this->method ?: 'GET');
        $infileData       = null;
        while (true) {
            $client = $this->client;
            if ($deadline !== null && self::remainingTimeout($deadline) <= 0) {
                return $this->setTimeoutError($timeBegin);
            }
            /*
             * Http Proxy
             */
            if ($this->proxy) {
                $parse         = parse_url($this->proxy);
                $proxy         = $parse['host'] ?? $parse['path'];
                $proxyPort     = $parse['port'] ?? $this->proxyPort;
                $proxyUsername = $parse['user'] ?? $this->proxyUsername;
                $proxyPassword = $parse['pass'] ?? $this->proxyPassword;
                $proxyType     = $parse['scheme'] ?? $this->proxyType;
                if (is_string($proxyType)) {
                    if ($proxyType === 'socks5') {
                        $proxyType = CURLPROXY_SOCKS5;
                    } else {
                        $proxyType = CURLPROXY_HTTP;
                    }
                }
                if ($proxyType === CURLPROXY_HTTP && (string) $proxyPassword !== '' && !($this->proxyAuth & CURLAUTH_BASIC)) {
                    throw new CurlException('swoole_curl_exec(): the password of the proxy can only be sent with a CURLOPT_PROXYAUTH value that includes CURLAUTH_BASIC');
                }

                if (!filter_var($proxy, FILTER_VALIDATE_IP)) {
                    $ip = System::gethostbyname($proxy, AF_INET, self::remainingTimeout($deadline, $this->clientOptions[Constant::OPTION_CONNECT_TIMEOUT] ?? -1));
                    if ($deadline !== null && self::remainingTimeout($deadline) <= 0) {
                        return $this->setTimeoutError($timeBegin);
                    }
                    if (!$ip) {
                        $this->setError(CURLE_COULDNT_RESOLVE_PROXY, 'Could not resolve proxy: ' . $proxy);
                        return false;
                    }
                    $proxy = $ip;
                }
                $proxyOptions = match ($proxyType) {
                    CURLPROXY_HTTP => [
                        'http_proxy_host'     => $proxy,
                        'http_proxy_port'     => $proxyPort,
                        'http_proxy_username' => $proxyUsername,
                        'http_proxy_password' => $proxyPassword,
                    ],
                    CURLPROXY_SOCKS5 => [
                        'socks5_host'     => $proxy,
                        'socks5_port'     => $proxyPort,
                        'socks5_username' => $proxyUsername,
                        'socks5_password' => $proxyPassword,
                    ],
                    default => throw new CurlException("Unexpected proxy type [{$proxyType}]"),
                };
            }
            /*
             * Client Options
             */
            $client->set(
                $this->clientOptions +
                [Constant::OPTION_SSL_HOST_NAME => trim((string) parse_url($this->info['url'], PHP_URL_HOST), '[]')] +
                ($proxyOptions ?? [])
            );
            /*
             * Method
             */
            $client->setMethod($this->customRequest !== '' ? $this->customRequest : $method);
            /*
             * Data
             */
            $requestHeaders      = $this->getRequestHeaders();
            $client->requestBody = null;
            $client->uploadFiles = null;
            $sendBody            = $method === 'POST' || $method === 'PUT';
            if ($sendBody && ($this->infile || $infileData !== null)) {
                // Infile
                // Notice: we make its priority higher than postData but raw cURL will send both of them
                if ($infileData === null) {
                    $infileData = '';
                    while (true) {
                        $nLength = $this->infileSize < 0 ? 8192 : min(8192, $this->infileSize - strlen($infileData));
                        if ($nLength === 0 || feof($this->infile)) {
                            break;
                        }
                        $data = fread($this->infile, $nLength);
                        if ($data === false) {
                            $this->setError(CURLE_READ_ERROR);
                            return false;
                        }
                        $infileData .= $data;
                    }
                }
                $client->setData($infileData);
            } elseif ($sendBody && $this->postData !== null) {
                // POST data
                $postData = $this->postData;
                if (is_string($postData)) {
                    if (!$this->hasHeader('content-type')) {
                        $requestHeaders['Content-Type'] = 'application/x-www-form-urlencoded';
                    }
                } elseif (is_array($postData)) {
                    foreach ($postData as $k => $v) {
                        if ($v instanceof \CURLFile) {
                            $client->addFile($v->getFilename(), $k, $v->getMimeType() ?: 'application/octet-stream', $v->getPostFilename());
                            unset($postData[$k]);
                        }
                    }
                }
                $client->setData($postData);
            }
            /*
             * Headers
             */
            // Notice: setHeaders must be placed last, because headers may be changed by other parts
            // As much as possible to ensure that Host is the first header.
            // See: http://tools.ietf.org/html/rfc7230#section-5.4
            $client->setHeaders($requestHeaders);
            // With several addresses to try, CURLOPT_CONNECTTIMEOUT is the time to connect to any of them, as with
            // libcurl: each address gets its share of the time that is left.
            $failover       = count($this->resolveAll) > 1 && empty($proxyOptions) && !$this->unix_socket_path;
            $connectTimeout = (float) ($this->clientOptions[Constant::OPTION_CONNECT_TIMEOUT] ?? 0);
            $connectBegin   = microtime(true);
            if ($failover && $this->resolveNext && $connectTimeout > 0) {
                $client->set([Constant::OPTION_CONNECT_TIMEOUT => $connectTimeout / (1 + count($this->resolveNext))]);
            }
            /*
             * Pre-request Callback
             */
            if ($this->prereqFunction && !$this->invokePrereqFunction($proxy ?? null, $proxyPort ?? null, $deadline)) {
                $this->info['total_time'] = microtime(true) - $timeBegin;
                return false;
            }
            /**
             * Execute.
             */
            $executeResult = $this->executeRequest($client, $deadline, $timedOut);
            // The next address of CURLOPT_RESOLVE, when this one cannot be connected to. Not with a proxy or a unix
            // socket, where the connection is not made to the address. Not once the time of CURLOPT_CONNECTTIMEOUT
            // or CURLOPT_TIMEOUT is up. Without either, each address gets the connect timeout of the client.
            while (!$executeResult && !$timedOut && $failover && $this->resolveNext
                && $client->statusCode === SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED
                && ($deadline === null || self::remainingTimeout($deadline) > 0)
            ) {
                $nextConnectTimeout = null;
                if ($connectTimeout > 0) {
                    $left = $connectBegin + $connectTimeout - microtime(true);
                    if ($left <= 0) {
                        break;
                    }
                    $nextConnectTimeout = $left / count($this->resolveNext);
                }
                $client = $this->useNextAddress($client, $nextConnectTimeout);
                if ($this->prereqFunction && !$this->invokePrereqFunction(null, null, $deadline)) {
                    $this->info['total_time'] = microtime(true) - $timeBegin;
                    return false;
                }
                $executeResult = $this->executeRequest($client, $deadline, $timedOut);
            }
            if (!$executeResult && $failover && $client->statusCode === SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED) {
                // No address could be connected to in time. The next call on the handle starts again from the first
                // one, as with libcurl, and not from the address that failed last.
                $this->resolveNext = $this->resolveAll;
                $this->useNextAddress($client);
            }
            $this->updateResponseInfo($client, $timeBegin);
            if (!$executeResult) {
                if ($this->writeError) {
                    $this->setError(CURLE_WRITE_ERROR, 'Failure writing output to destination');
                    $this->info['total_time'] = microtime(true) - $timeBegin;
                    return false;
                }
                if ($timedOut || $client->statusCode === SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT
                    || $client->errCode === SWOOLE_ERROR_DNSLOOKUP_RESOLVE_TIMEOUT) {
                    return $this->setTimeoutError($timeBegin);
                }
                $errCode = $client->errCode;
                if ($errCode == SWOOLE_ERROR_DNSLOOKUP_RESOLVE_FAILED || $errCode == SWOOLE_ERROR_DNSLOOKUP_RESOLVE_TIMEOUT) {
                    $this->setError(CURLE_COULDNT_RESOLVE_HOST, 'Could not resolve host: ' . $client->host);
                } elseif ($errCode === SWOOLE_ERROR_SSL_VERIFY_FAILED) {
                    $this->setError(CURLE_SSL_CACERT, $client->errMsg);
                } elseif (in_array($errCode, [SWOOLE_ERROR_SSL_HANDSHAKE_FAILED, SWOOLE_ERROR_SSL_BAD_PROTOCOL,
                    SWOOLE_ERROR_SSL_CREATE_CONTEXT_FAILED, SWOOLE_ERROR_SSL_CREATE_SESSION_FAILED], true)) {
                    $this->setError(CURLE_SSL_CONNECT_ERROR, $client->errMsg);
                } elseif ($client->statusCode === SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED) {
                    $this->setError(CURLE_COULDNT_CONNECT, $client->errMsg);
                } else {
                    $this->setError($errCode, $client->errMsg);
                }
                $this->info['total_time'] = microtime(true) - $timeBegin;
                return false;
            }
            $this->info['http_code'] = $client->statusCode;
            if ($client->statusCode >= 300 && $client->statusCode < 400 && isset($client->headers['location'])) {
                $redirectParsedUrl = $this->getRedirectUrl($client->headers['location']);
                if (!$redirectParsedUrl) {
                    $this->setError(CURLE_URL_MALFORMAT, 'The redirect URL is malformed');
                    $transferFailed = true;
                    break;
                }
                $redirectUrl       = self::unparseUrl($redirectParsedUrl);
                if ($this->followLocation) {
                    if ($this->maxRedirects >= 0 && $this->info['redirect_count'] >= $this->maxRedirects) {
                        $this->info['redirect_url'] = $redirectUrl;
                        $this->setError(CURLE_TOO_MANY_REDIRECTS, "Maximum ({$this->maxRedirects}) redirects followed");
                        $transferFailed = true;
                        break;
                    }
                    if (!$this->checkProtocol($redirectParsedUrl['scheme'] ?? 'http', true)) {
                        $this->info['http_code']  = $client->statusCode;
                        $this->info['total_time'] = microtime(true) - $timeBegin;
                        return false;
                    }
                    if ($this->info['redirect_count'] === 0) {
                        $redirectBeginTime                = microtime(true);
                    }
                    // Redirects change this transfer's mode without changing the configured request.
                    if (($method === 'POST' && in_array($client->statusCode, [Status::MOVED_PERMANENTLY, Status::FOUND]))
                        || ($method !== 'HEAD' && $client->statusCode === Status::SEE_OTHER)) {
                        $method = 'GET';
                    }
                    if ($this->autoReferer) {
                        $this->setHeader('Referer', $this->info['url']);
                    }
                    if (!$this->setUrlInfo($redirectParsedUrl)) {
                        return false;
                    }
                    $this->setUrl($redirectUrl, false);
                    $this->info['redirect_count']++;
                } else {
                    $this->info['redirect_url'] = $redirectUrl;
                    break;
                }
            } elseif ($this->failOnError && $client->statusCode >= 400) {
                $this->setError(CURLE_HTTP_RETURNED_ERROR, "The requested URL returned error: {$client->statusCode} " . Status::getReasonPhrase($client->statusCode));
                $transferFailed = true;
                break;
            } else {
                break;
            }
        }
        $this->info['total_time']     = microtime(true) - $timeBegin;
        $this->info['http_code']      = $client->statusCode;
        $this->info['speed_download'] = $this->info['size_download'] / max($this->info['total_time'], 1e-9);
        $this->info['speed_upload']   = $this->info['size_upload'] / max($this->info['total_time'], 1e-9);
        if (isset($redirectBeginTime)) {
            $this->info['redirect_time'] = microtime(true) - $redirectBeginTime;
        }

        $headerContent = '';
        if ($client->headers) {
            $cb = $this->headerFunction;
            if ($client->statusCode > 0) {
                $row = "HTTP/1.1 {$client->statusCode} " . Status::getReasonPhrase($client->statusCode) . "\r\n";
                if ($cb) {
                    $cb($this, $row);
                }
                $headerContent .= $row;
            }
            foreach ($client->headers as $k => $v) {
                $list = is_array($v) ? $v : [$v];
                foreach ($list as $_v) {
                    $row = "{$k}: {$_v}\r\n";
                    if ($cb) {
                        $cb($this, $row);
                    }
                    $headerContent .= $row;
                }
            }
            $headerContent .= "\r\n";
            if ($cb) {
                $cb($this, '');
            }
        }

        if ($this->withHeader) {
            $transfer = $headerContent . $client->body;
        } else {
            $transfer = $client->body;
        }

        if ($this->withHeaderOut) {
            $headerOutContent             = $client->getHeaderOut();
            $this->info['request_header'] = $headerOutContent ? $headerOutContent . "\r\n\r\n" : '';
        }
        if ($this->withFileTime) {
            if (isset($client->headers['last-modified'])) {
                $this->info['filetime'] = strtotime($client->headers['last-modified']);
            } else {
                $this->info['filetime'] = -1;
            }
        }

        if (!empty($this->cookieJar)) {
            if ($this->cookieJar === '-') {
                foreach ((array) $client->set_cookie_headers as $cookie) {
                    echo $cookie . PHP_EOL;
                }
            } else {
                $cookies = '';
                foreach ((array) $client->set_cookie_headers as $cookie) {
                    $cookies .= "{$cookie};";
                }
                file_put_contents($this->cookieJar, $cookies);
            }
        }

        if ($transferFailed) {
            return false;
        }
        if ($this->writeFunction !== null) {
            return true;
        }
        if ($client->body && $this->readFunction) {
            $cb = $this->readFunction;
            $cb($this, $this->outputStream, strlen($client->body));
        }
        if ($this->returnTransfer) {
            return $this->transfer = $transfer;
        }
        if ($this->outputStream) {
            return fwrite($this->outputStream, $transfer) === strlen($transfer);
        }
        echo $transfer;

        return true;
    }

    private function updateResponseInfo(Client $client, float $timeBegin): void
    {
        $stats = $client->stats ?? [];
        foreach (['namelookup_time', 'connect_time', 'appconnect_time', 'pretransfer_time'] as $key) {
            $this->info[$key] += $stats[$key] ?? 0.0;
        }
        foreach (['primary_ip', 'primary_port', 'local_ip', 'local_port', 'http_version', 'ssl_verify_result'] as $key) {
            if (isset($stats[$key])) {
                $this->info[$key] = $stats[$key];
            }
        }
        $this->info['request_size'] += $stats['request_size'] ?? 0;
        $this->info['header_size'] += $stats['header_size'] ?? 0;
        if (isset($stats['starttransfer_time'])) {
            $this->info['starttransfer_time'] = microtime(true) - $timeBegin - ($stats['total_time'] ?? 0)
                + $stats['starttransfer_time'];
        }
        if ($client->statusCode <= 0) {
            return;
        }
        $this->info['content_type']            = $client->headers['content-type'] ?? null;
        $this->info['size_download']           = $this->failOnError && $client->statusCode >= 400 ? 0.0 : (float) ($stats['size_download'] ?? strlen($client->body));
        $this->info['download_content_length'] = (float) ($stats['download_content_length'] ?? -1);
        $this->info['size_upload']             = (float) ($stats['size_upload'] ?? 0);
        $this->info['upload_content_length']   = (float) ($stats['upload_content_length'] ?? 0);
        $scheme                                = strtolower((string) parse_url($this->info['url'], PHP_URL_SCHEME));
        $this->info['scheme']                  = strtoupper($scheme);
        $this->info['protocol']                = $scheme === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP;
    }

    private static function remainingTimeout(?float $deadline, float $timeout = -1): float
    {
        if ($deadline === null) {
            return $timeout;
        }
        $remaining = $deadline - hrtime(true) / 1e9;
        return $timeout > 0 ? min($timeout, $remaining) : $remaining;
    }

    private function setTimeoutError(float $timeBegin): bool
    {
        $this->setError(CURLE_OPERATION_TIMEDOUT, 'Operation timed out');
        $this->info['total_time'] = microtime(true) - $timeBegin;
        return false;
    }

    private function executeRequest(Client $client, ?float $deadline, bool &$timedOut): bool
    {
        if ($deadline === null) {
            // Zero means unlimited in cURL, but selects the default timeout in the HTTP client.
            $client->set([Constant::OPTION_TIMEOUT => -1]);
            return $client->execute($this->getUrl());
        }
        $remaining = self::remainingTimeout($deadline);
        if ($remaining <= 0) {
            $timedOut = true;
            return false;
        }
        $client->set([Constant::OPTION_TIMEOUT => $remaining]);
        // A receive timeout alone does not include DNS, connection, TLS or request writes.
        $timer = Timer::after(max(1, (int) ceil($remaining * 1000)), static function () use ($client, &$timedOut): void {
            $timedOut = true;
            $client->close();
        });
        try {
            $result = $client->execute($this->getUrl());
        } finally {
            if (!$timedOut) {
                Timer::clear($timer);
            }
        }
        if ($timedOut || self::remainingTimeout($deadline) <= 0) {
            $timedOut = true;
            if ($client->connected) {
                $client->close();
            }
            return false;
        }
        return $result;
    }

    /**
     * Invokes the CURLOPT_PREREQFUNCTION callback right before the request is sent out.
     *
     * Native cURL fires this callback once the connection is fully established. The coroutine HTTP client
     * connects and sends within a single call, so on a fresh connection the callback fires before the
     * connection exists: the local address is reported as an empty string with port 0, and the primary
     * address is the resolved address the client is about to connect to. When an established connection
     * is reused, the real socket addresses are reported. With several addresses for the host
     * (CURLOPT_RESOLVE), the callback is invoked for each address tried, where native cURL invokes it once.
     *
     * @return bool false when the transfer must be aborted; the error has been recorded already
     */
    private function invokePrereqFunction(?string $proxyIp, ?int $proxyPort, ?float $deadline): bool
    {
        $primaryIp   = '';
        $primaryPort = 0;
        $localIp     = '';
        $localPort   = 0;
        if ($this->client->connected) {
            $peer = $this->client->getpeername();
            if (is_array($peer)) {
                $primaryIp   = $peer['address'];
                $primaryPort = $peer['port'];
            }
            $sock = $this->client->getsockname();
            if (is_array($sock)) {
                $localIp   = $sock['address'];
                $localPort = $sock['port'];
            }
        } elseif ($this->unix_socket_path) {
            $primaryIp   = $this->unix_socket_path;
            $primaryPort = $this->urlInfo['port'];
        } elseif ($proxyIp !== null) {
            // The connection is made to the proxy; native cURL reports the proxy as the connection peer.
            $primaryIp   = $proxyIp;
            $primaryPort = (int) $proxyPort;
        } else {
            $host = $this->urlInfo['host'];
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $primaryIp = $host;
            } else {
                $remaining = self::remainingTimeout($deadline, $this->clientOptions[Constant::OPTION_CONNECT_TIMEOUT] ?? -1);
                if ($deadline !== null && $remaining <= 0) {
                    return true;
                }
                $ip = System::gethostbyname($host, AF_INET, $remaining);
                if (!$ip) {
                    // Native cURL never invokes the callback when the connection cannot be established;
                    // let Client::execute() fail with the canonical DNS error.
                    return true;
                }
                $primaryIp = $ip;
            }
            $primaryPort = $this->urlInfo['port'];
        }

        $retval = ($this->prereqFunction)($this, $primaryIp, $localIp, $primaryPort, $localPort);
        if ($retval === CURL_PREREQFUNC_OK) {
            return true;
        }
        $this->setError(CURLE_ABORTED_BY_CALLBACK, 'operation aborted by pre-request callback');
        if ($retval === CURL_PREREQFUNC_ABORT) {
            return false;
        }
        $message = 'The CURLOPT_PREREQFUNCTION callback must return either CURL_PREREQFUNC_OK or CURL_PREREQFUNC_ABORT';
        throw is_int($retval) ? new \ValueError($message) : new \TypeError($message);
    }

    /* ====== Redirect helper ====== */

    private static function unparseUrl(array $parsedUrl): string
    {
        $scheme   = ($parsedUrl['scheme'] ?? 'http') . '://';
        $host     = $parsedUrl['host'] ?? '';
        $port     = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        $user     = $parsedUrl['user'] ?? '';
        $pass     = isset($parsedUrl['pass']) ? ':' . $parsedUrl['pass'] : '';
        $pass     = ($user || $pass) ? "{$pass}@" : '';
        $path     = $parsedUrl['path'] ?? '';
        $query    = (isset($parsedUrl['query']) && $parsedUrl['query'] !== '') ? '?' . $parsedUrl['query'] : '';
        $fragment = isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '';
        return $scheme . $user . $pass . $host . $port . $path . $query . $fragment;
    }

    private function getRedirectUrl(string $location): array
    {
        $uri = parse_url($location);
        if (!is_array($uri)) {
            return [];
        }
        // Resolve against the logical URL, not a connection address from CURLOPT_RESOLVE.
        $base = parse_url($this->info['url']);
        if (isset($uri['scheme']) || isset($uri['host'])) {
            $redirectUri = $uri;
            $redirectUri['scheme'] ??= $base['scheme'] ?? 'http';
        } else {
            $redirectUri = $base;
            unset($redirectUri['fragment']);
            $path        = $uri['path'] ?? '';
            if ($path !== '') {
                if ($path[0] !== '/') {
                    $basePath = $base['path'] ?? '/';
                    $path     = substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $path;
                }
                $redirectUri['path'] = $path;
                unset($redirectUri['query']);
            }
            foreach (['query', 'fragment'] as $key) {
                if (isset($uri[$key])) {
                    $redirectUri[$key] = $uri[$key];
                }
            }
        }
        if (isset($redirectUri['path'])) {
            $redirectUri['path'] = self::removeDotSegments($redirectUri['path']);
        }
        return $redirectUri;
    }

    private static function removeDotSegments(string $path): string
    {
        $segments = explode('/', $path);
        $result   = [];
        $last     = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($segment === '..') {
                if (count($result) > 1) {
                    array_pop($result);
                }
            } elseif ($segment !== '.') {
                $result[] = $segment;
                continue;
            }
            if ($index === $last) {
                $result[] = '';
            }
        }
        return implode('/', $result);
    }
}
