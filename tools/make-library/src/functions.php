<?php

if (!defined('EMOJI_OK')) {
    define('EMOJI_OK', '✅');
}
if (!defined('EMOJI_SUCCESS')) {
    define('EMOJI_SUCCESS', '🚀');
}
if (!defined('EMOJI_ERROR')) {
    define('EMOJI_ERROR', '❌');
}
if (!defined('EMOJI_WARN')) {
    define('EMOJI_WARN', '⚠️');
}

if (!defined('SWOOLE_COLOR_RED')) {
    define('SWOOLE_COLOR_RED', 1);
    define('SWOOLE_COLOR_GREEN', 2);
    define('SWOOLE_COLOR_YELLOW', 3);
    define('SWOOLE_COLOR_BLUE', 4);
    define('SWOOLE_COLOR_MAGENTA', 5);
    define('SWOOLE_COLOR_CYAN', 6);
    define('SWOOLE_COLOR_WHITE', 7);
}

function swoole_log(string $content, int $color = 0): void
{
    echo ($color ? "\033[3{$color}m{$content}\033[0m" : $content) . PHP_EOL;
}

function swoole_check(bool $is_ok, string $output): void
{
    if ($is_ok) {
        swoole_ok("{$output} OK!");
    } else {
        swoole_error("{$output} Failed!");
    }
}

function swoole_warn(string ...$args): void
{
    foreach ($args as $arg) {
        swoole_log(EMOJI_WARN . " {$arg}", SWOOLE_COLOR_YELLOW);
    }
}

function swoole_error(string ...$args): void
{
    foreach ($args as $arg) {
        swoole_log(EMOJI_ERROR . " {$arg}", SWOOLE_COLOR_RED);
    }
    exit(255);
}

function swoole_ok(string ...$args): void
{
    foreach ($args as $arg) {
        swoole_log(EMOJI_OK . " {$arg}", SWOOLE_COLOR_GREEN);
    }
}

function swoole_success(string $content): void
{
    swoole_log(
        str_repeat(EMOJI_SUCCESS, 3) . $content . str_repeat(EMOJI_SUCCESS, 3),
        SWOOLE_COLOR_CYAN
    );
    exit(0);
}

function space(int $length): string
{
    return str_repeat(' ', $length);
}

function unCamelize(string $camelCaps, string $separator = '_'): string
{
    $camelCaps = preg_replace('/([a-z])([A-Z])/', "\${1}{$separator}\${2}", $camelCaps);
    /* for special case like: PDOPool => pdo_pool */
    $camelCaps = preg_replace('/([A-Z]+)([A-Z][a-z]+)/', "\${1}{$separator}\${2}\${3}", $camelCaps);
    return strtolower($camelCaps);
}
