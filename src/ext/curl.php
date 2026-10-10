<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

/* @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

function swoole_curl_init(string $url = ''): Swoole\Curl\Handler
{
    return new Swoole\Curl\Handler($url);
}

function swoole_curl_setopt(Swoole\Curl\Handler $obj, int $opt, mixed $value): bool
{
    return $obj->setOpt($opt, $value);
}

function swoole_curl_setopt_array(Swoole\Curl\Handler $obj, array $array): bool
{
    foreach ($array as $k => $v) {
        if ($obj->setOpt($k, $v) !== true) {
            return false;
        }
    }
    return true;
}

function swoole_curl_exec(Swoole\Curl\Handler $obj): string|bool
{
    return $obj->exec();
}

function swoole_curl_getinfo(Swoole\Curl\Handler $obj, int $opt = 0): mixed
{
    $info = $obj->getInfo();
    if (is_array($info) && $opt) {
        return match ($opt) {
            CURLINFO_EFFECTIVE_URL           => $info['url'],
            CURLINFO_HTTP_CODE               => $info['http_code'],
            CURLINFO_CONTENT_TYPE            => $info['content_type'],
            CURLINFO_REDIRECT_COUNT          => $info['redirect_count'],
            CURLINFO_REDIRECT_URL            => $info['redirect_url'],
            CURLINFO_TOTAL_TIME              => $info['total_time'],
            CURLINFO_STARTTRANSFER_TIME      => $info['starttransfer_time'],
            CURLINFO_SIZE_DOWNLOAD           => $info['size_download'],
            CURLINFO_SPEED_DOWNLOAD          => $info['speed_download'],
            CURLINFO_REDIRECT_TIME           => $info['redirect_time'],
            CURLINFO_HEADER_SIZE             => $info['header_size'],
            CURLINFO_HEADER_OUT              => $info['request_header'] ?? '',
            CURLINFO_PRIMARY_IP              => $info['primary_ip'],
            CURLINFO_PRIMARY_PORT            => $info['primary_port'],
            CURLINFO_LOCAL_IP                => $info['local_ip'],
            CURLINFO_LOCAL_PORT              => $info['local_port'],
            CURLINFO_NAMELOOKUP_TIME         => $info['namelookup_time'],
            CURLINFO_CONNECT_TIME            => $info['connect_time'],
            CURLINFO_APPCONNECT_TIME         => $info['appconnect_time'],
            CURLINFO_PRETRANSFER_TIME        => $info['pretransfer_time'],
            CURLINFO_REQUEST_SIZE            => $info['request_size'],
            CURLINFO_FILETIME                => $info['filetime'],
            CURLINFO_SIZE_UPLOAD             => $info['size_upload'],
            CURLINFO_SPEED_UPLOAD            => $info['speed_upload'],
            CURLINFO_CONTENT_LENGTH_DOWNLOAD => $info['download_content_length'],
            CURLINFO_CONTENT_LENGTH_UPLOAD   => $info['upload_content_length'],
            CURLINFO_SSL_VERIFYRESULT        => $info['ssl_verify_result'],
            CURLINFO_CERTINFO                => $info['certinfo'],
            CURLINFO_HTTP_VERSION            => $info['http_version'],
            CURLINFO_PROTOCOL                => $info['protocol'],
            CURLINFO_SCHEME                  => $info['scheme'],
            CURLINFO_PRIVATE                 => $info['private'],
            default                          => swoole_curl_getinfo_integer($info, $opt),
        };
    }
    return $info;
}

function swoole_curl_getinfo_integer(array $info, int $opt): ?int
{
    static $options = null;
    if ($options === null) {
        $options = [];
        foreach (['TOTAL_TIME', 'NAMELOOKUP_TIME', 'CONNECT_TIME', 'APPCONNECT_TIME', 'PRETRANSFER_TIME',
            'STARTTRANSFER_TIME', 'REDIRECT_TIME', 'SIZE_UPLOAD', 'SIZE_DOWNLOAD', 'SPEED_UPLOAD', 'SPEED_DOWNLOAD',
            'CONTENT_LENGTH_UPLOAD', 'CONTENT_LENGTH_DOWNLOAD', 'FILETIME'] as $name) {
            $constant = 'CURLINFO_' . $name . '_T';
            if (defined($constant)) {
                $key = match ($name) {
                    'CONTENT_LENGTH_UPLOAD'   => 'upload_content_length',
                    'CONTENT_LENGTH_DOWNLOAD' => 'download_content_length',
                    default                   => strtolower($name),
                };
                $options[constant($constant)] = [$key, str_ends_with($name, '_TIME') ? 1000000 : 1];
            }
        }
    }
    if (!isset($options[$opt])) {
        return null;
    }
    [$key, $scale] = $options[$opt];
    return (int) round($info[$key] * $scale);
}

function swoole_curl_errno(Swoole\Curl\Handler $obj): int
{
    return $obj->errno();
}

function swoole_curl_error(Swoole\Curl\Handler $obj): string
{
    return $obj->error();
}

function swoole_curl_reset(Swoole\Curl\Handler $obj): void
{
    $obj->reset();
}

function swoole_curl_close(Swoole\Curl\Handler $obj): void
{
    $obj->close();
}

function swoole_curl_multi_getcontent(Swoole\Curl\Handler $obj): ?string
{
    return $obj->getContent();
}
