<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Constant;
use Swoole\Coroutine\FastCGI\Proxy;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require dirname(__DIR__, 2) . '/bootstrap.php';

$documentRoot = '/var/www/html';
$server       = new Server('0.0.0.0', 80, SWOOLE_BASE);
$server->set([
    Constant::OPTION_WORKER_NUM               => swoole_cpu_num() * 2,
    Constant::OPTION_HTTP_PARSE_COOKIE        => false,
    Constant::OPTION_HTTP_PARSE_POST          => false,
    Constant::OPTION_DOCUMENT_ROOT            => $documentRoot,
    Constant::OPTION_ENABLE_STATIC_HANDLER    => true,
    Constant::OPTION_STATIC_HANDLER_LOCATIONS => ['/wp-admin', '/wp-content', '/wp-includes'],
]);
$proxy = new Proxy('wordpress:9000', $documentRoot);
$server->on('request', function (Request $request, Response $response) use ($proxy, $documentRoot) {
    // Requests to /wp-login.php, /wp-signup.php, etc should not be processed using /index.php.
    if (!is_readable($documentRoot . $request->server['path_info'])) {
        $request->server['path_info'] = '/index.php';
    }
    $proxy->pass($request, $response);
});
$server->start();
