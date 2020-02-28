<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Coroutine\FastCGI\Proxy;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require __DIR__ . '/../../bootstrap.php';

$documentRoot = getenv('DOCUMENT_ROOT');
if (!$documentRoot || !is_dir($documentRoot)) {
    throw new InvalidArgumentException('Invalid document root');
}

$server = new Server('127.0.0.1', 80, SWOOLE_BASE);
$server->set([
    'worker_num' => swoole_cpu_num() * 2,
    'http_parse_cookie' => false,
    'http_parse_post' => false,
]);
$proxy = new Proxy('php-fpm:9000', $documentRoot);
$server->on('request', function (Request $request, Response $response) use ($proxy) {
    $proxy->pass($request, $response);
});
$server->start();
