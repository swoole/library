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

use Swoole\Coroutine\System;

function swoole_gethostbynamel(string $domain): array|false
{
    return System::getaddrinfo($domain);
}

function swoole_mail(string $to, string $subject, string $message, array|string $additional_headers = [], string $additional_params = ''): bool
{
    $client = swoole_get_default_remote_object_client();
    return $client->call('mail', $to, $subject, $message, $additional_headers, $additional_params);
}

function swoole_checkdnsrr(string $hostname, string $type = 'MX'): bool
{
    $client = swoole_get_default_remote_object_client();
    return $client->call('checkdnsrr', ...func_get_args());
}

function swoole_dns_check_record(string $hostname, string $type = 'MX'): bool
{
    return swoole_checkdnsrr($hostname, $type);
}

function swoole_real_getmxrr(string $hostname, mixed $hosts = null, mixed $weights = null): array
{
    $result = getmxrr($hostname, $hosts, $weights);
    return ['result' => $result, 'host' => $hosts, 'weight' => $weights];
}

/**
 * @param-out array $hosts
 * @param-out array|null $weights
 */
function swoole_getmxrr(string $hostname, mixed &$hosts, mixed &$weights = null): bool
{
    $client = swoole_get_default_remote_object_client();
    $result = $client->call('swoole_real_getmxrr', $hostname);
    $hosts  = $result['host'];
    if (func_num_args() >= 3) {
        $weights = $result['weight'];
    }
    return $result['result'];
}

/**
 * @param-out array $hosts
 * @param-out array|null $weights
 */
function swoole_dns_get_mx(string $hostname, mixed &$hosts, mixed &$weights = null): bool
{
    return func_num_args() >= 3 ? swoole_getmxrr($hostname, $hosts, $weights) : swoole_getmxrr($hostname, $hosts);
}

function swoole_real_dns_get_record(string $hostname, int $type, mixed $authoritative_name_servers = null, mixed $additional_records = null, bool $raw = false): array
{
    $result = dns_get_record($hostname, $type, $authoritative_name_servers, $additional_records, $raw);
    return ['result' => $result, 'authoritative_name_servers' => $authoritative_name_servers, 'additional_records' => $additional_records];
}

/**
 * @param-out array|null $authoritative_name_servers
 * @param-out array|null $additional_records
 */
function swoole_dns_get_record(string $hostname,
    int $type = DNS_ANY,
    mixed &$authoritative_name_servers = null,
    mixed &$additional_records = null,
    bool $raw = false): array|false
{
    $client = swoole_get_default_remote_object_client();
    // These are output parameters; their old values need not be serializable or of any particular type.
    $result = $client->call('swoole_real_dns_get_record', $hostname, $type, null, null, $raw);
    if (func_num_args() >= 3) {
        $authoritative_name_servers = $result['authoritative_name_servers'];
    }
    if (func_num_args() >= 4) {
        $additional_records = $result['additional_records'];
    }
    return $result['result'];
}

function swoole_gethostbyaddr(string $ip): string
{
    $client = swoole_get_default_remote_object_client();
    return $client->call('gethostbyaddr', $ip);
}
