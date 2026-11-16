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

function swoole_curl_getinfo(Swoole\Curl\Handler $obj, ?int $opt = null): mixed
{
    return $obj->getInfo($opt);
}

function swoole_curl_getinfo_integer(array $info, int $opt): int|false
{
    static $options = null;
    if ($options === null) {
        $options = [];
        foreach (['TOTAL_TIME', 'NAMELOOKUP_TIME', 'CONNECT_TIME', 'APPCONNECT_TIME', 'PRETRANSFER_TIME',
            'STARTTRANSFER_TIME', 'REDIRECT_TIME', 'POSTTRANSFER_TIME', 'SIZE_UPLOAD', 'SIZE_DOWNLOAD', 'SPEED_UPLOAD', 'SPEED_DOWNLOAD',
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
        return false;
    }
    [$key, $scale] = $options[$opt];
    if (!isset($info[$key]) || !is_numeric($info[$key]) || !is_finite((float) $info[$key])) {
        return false;
    }
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
