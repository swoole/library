<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Helloworld\HelloReply;
use Helloworld\HelloRequest;

require __DIR__ . '/../bootstrap.php';

$http = new Swoole\Http\Server('/tmp/grpc.sock', 0, SWOOLE_BASE, SWOOLE_SOCK_UNIX_STREAM);
$http->set([
    'log_level'           => SWOOLE_LOG_INFO,
    'trace_flags'         => 0,
    'worker_num'          => 1,
    'open_http2_protocol' => true,
]);
$http->on('workerStart', function (Swoole\Http\Server $server) {
    echo 'php ' . __DIR__ . "/greeter_unix_client.php\n";
});
$http->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($http) {
    $path  = $request->server['request_uri'];
    $route = [
        '/helloworld.Greeter/SayHello' => function (...$args) {
            [$server, $request, $response] = $args;
            /*@var $request_message HelloRequest */
            $request_message = Swoole\Grpc\Parser::deserializeMessage([HelloRequest::class, null], $request->rawContent());
            if ($request_message) {
                $response_message = new HelloReply();
                $response_message->setMessage('Hello ' . $request_message->getName());
                $response->header('content-type', 'application/grpc');
                $response->header('trailer', 'grpc-status, grpc-message');
                $trailer = [
                    'grpc-status'  => '0',
                    'grpc-message' => '',
                ];
                foreach ($trailer as $trailer_name => $trailer_value) {
                    $response->trailer($trailer_name, $trailer_value);
                }
                $response->end(Swoole\Grpc\Parser::serializeMessage($response_message));
                return true;
            }
            return false;
        },
    ];
    if (!(isset($route[$path]) && $route[$path]($http, $request, $response))) {
        $response->status(400);
        $response->end('Bad Request');
    }
});
$http->start();
