<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

$body = file_get_contents('php://input');
header('Content-Type: application/json');
echo json_encode([
    'method'       => $_SERVER['REQUEST_METHOD'],
    'body_length'  => strlen($body),
    'body_hash'    => hash('sha256', $body),
    'script_name'  => $_SERVER['SCRIPT_NAME'],
    'document_uri' => $_SERVER['DOCUMENT_URI'],
    'request_uri'  => $_SERVER['REQUEST_URI'],
    'query'        => $_SERVER['QUERY_STRING'],
    'raw_cookie'   => $_SERVER['HTTP_COOKIE'] ?? '',
    'cookie'       => $_COOKIE,
    'get'          => $_GET,
    'post'         => $_POST,
]);
