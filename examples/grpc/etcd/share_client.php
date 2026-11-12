<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Swoole\Coroutine;

Coroutine::create(function () {
    $grpcClient = new Swoole\Grpc\Client(\Swoole\Grpc\Example\SERVER_URI);
    // use in different type clients

    Coroutine::create(function () use ($grpcClient) {
        $kvClient = new Etcdserverpb\KVClient(\Swoole\Grpc\Example\SERVER_URI, ['use' => $grpcClient]);
        $request  = new Etcdserverpb\PutRequest();
        $request->setPrevKv(true);
        $request->setKey('Hello');
        $request->setValue('Swoole');
        [$reply, $status] = $kvClient->Put($request);
        if ($status === 0) {
            echo "\n=== PUT KV OK ===\n";
        } else {
            echo "Error#{$status}: {$reply}\n";
        }
    });

    Coroutine::create(function () use ($grpcClient) {
        $authClient = new Etcdserverpb\AuthClient(\Swoole\Grpc\Example\SERVER_URI, ['use' => $grpcClient]);

        $userRequest = new Etcdserverpb\AuthUserAddRequest();
        $userNames   = ['rango', 'twosee', 'gxh', 'stone', 'sjl'];
        foreach ($userNames as $username) {
            $userRequest->setName($username);
            [$reply, $status] = $authClient->UserAdd($userRequest);
            if ($status !== 0) {
                goto _error;
            }
        }

        $useListRequest   = new Etcdserverpb\AuthUserListRequest();
        [$reply, $status] = $authClient->UserList($useListRequest);
        if ($status !== 0) {
            goto _error;
        }
        echo "\n=== SHOW USER LIST ===\n";
        foreach ($reply->getUsers() as $user) {
            /*@var \Authpb\User */
            echo "* {$user}\n";
        }
        echo "=== SHOW USER LIST OK ===\n";

        $userRequest = new Etcdserverpb\AuthUserDeleteRequest();
        foreach ($userNames as $username) {
            $userRequest->setName($username);
            [$reply, $status] = $authClient->UserDelete($userRequest);
            if ($status !== 0) {
                goto _error;
            }
        }

        if (false) {
            _error:
            echo "Error#{$status}: {$reply}\n";
        }

        echo "\n=== SHOW ALL CLIENT STATS ===\n";
        var_dump(Swoole\Grpc\Client::numStats());
        $grpcClient->close();
    });
});
