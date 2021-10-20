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

function swoole_curl_setopt(Swoole\Curl\Handler $obj, int $opt, $value): bool
{
    return $obj->setOpt($opt, $value);
}

function swoole_curl_setopt_array(Swoole\Curl\Handler $obj, $array): bool
{
    foreach ($array as $k => $v) {
        if ($obj->setOpt($k, $v) !== true) {
            return false;
        }
    }
    return true;
}

function swoole_curl_exec(Swoole\Curl\Handler $obj)
{
    return $obj->exec();
}

function swoole_curl_getinfo(Swoole\Curl\Handler $obj, int $opt = 0)
{
    $info = $obj->getInfo();
    if (is_array($info) and $opt) {
        switch ($opt) {
            case CURLINFO_EFFECTIVE_URL:
                return $info['url'];
            case CURLINFO_HTTP_CODE:
                return $info['http_code'];
            case CURLINFO_CONTENT_TYPE:
                return $info['content_type'];
            case CURLINFO_REDIRECT_COUNT:
                return $info['redirect_count'];
            case CURLINFO_REDIRECT_URL:
                return $info['redirect_url'];
            case CURLINFO_TOTAL_TIME:
                return $info['total_time'];
            case CURLINFO_STARTTRANSFER_TIME:
                return $info['starttransfer_time'];
            case CURLINFO_SIZE_DOWNLOAD:
                return $info['size_download'];
            case CURLINFO_SPEED_DOWNLOAD:
                return $info['speed_download'];
            case CURLINFO_REDIRECT_TIME:
                return $info['redirect_time'];
            case CURLINFO_HEADER_SIZE:
                return $info['header_size'];
            case CURLINFO_PRIMARY_IP:
                return $info['primary_ip'];
            case CURLINFO_PRIVATE:
                return $info['private'];
            default:
                return null;
        }
    }
    return $info;
}

function swoole_curl_errno(Swoole\Curl\Handler $obj)
{
    return $obj->errno();
}

function swoole_curl_error(Swoole\Curl\Handler $obj)
{
    return $obj->error();
}

function swoole_curl_reset(Swoole\Curl\Handler $obj)
{
    return $obj->reset();
}

function swoole_curl_close(Swoole\Curl\Handler $obj)
{
    return $obj->close();
}

function swoole_curl_multi_getcontent(Swoole\Curl\Handler $obj)
{
    return $obj->getContent();
}
