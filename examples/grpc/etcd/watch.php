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

use Etcdserverpb\WatchCreateRequest;
use Etcdserverpb\WatchCreateRequest\FilterType;
use Etcdserverpb\WatchRequest;
use Swoole\Coroutine;

// The Watcher
Coroutine::create(function () {
    $watchClient = new Etcdserverpb\WatchClient(\Swoole\Grpc\Example\SERVER_URI);

    $watchCall     = $watchClient->Watch();
    $request       = new WatchRequest();
    $createRequest = new WatchCreateRequest();
    $createRequest->setKey('Hello');
    $request->setCreateRequest($createRequest);

    _retry:
    $watchCall->push($request);
    /*@var $reply Etcdserverpb\WatchResponse */
    while (true) {
        [$reply, $status] = $watchCall->recv();
        if ($status === 0) { // success
            if ($reply->getCreated() || $reply->getCanceled()) {
                continue;
            }
            foreach ($reply->getEvents() as $event) {
                /*@var $event Mvccpb\Event */
                $type = $event->getType();
                $kv   = $event->getKv();
                if ($type === FilterType::NOPUT) {
                    echo "Put key {$kv->getKey()} => {$kv->getValue()}\n";
                    break;
                }
                if ($type === FilterType::NODELETE) {
                    echo "Delete key {$kv->getKey()}\n";
                    break;
                }
            }
        } else { // failed
            static $retry_time = 0;
            if ($watchClient->isConnected()) {
                $retry_time++;
                echo "Retry#{$retry_time}\n";
                goto _retry;
            }
            echo "Error#{$status}: {$reply}\n";
            break;
        }
    }
    $watchClient->close();
});

// The Writer Put and Delete
Coroutine::create(function () {
    $kvClient = new Etcdserverpb\KVClient(\Swoole\Grpc\Example\SERVER_URI);
    Coroutine::create(function () use ($kvClient) {
        $request = new Etcdserverpb\PutRequest();
        $request->setKey('Hello');
        $request->setPrevKv(true);
        while (true) {
            static $count = 0;
            Coroutine::sleep(.5);
            $request->setValue('Swoole#' . (++$count));
            [$reply, $status] = $kvClient->Put($request);
            if ($status !== 0) {
                echo "Error#{$status}: {$reply}\n";
                break;
            }
        }
        $kvClient->close();
    });
    Coroutine::create(function () use ($kvClient) {
        $request = new Etcdserverpb\DeleteRangeRequest();
        $request->setKey('Hello');
        $request->setPrevKv(true);
        while (true) {
            Coroutine::sleep(1);
            [$reply, $status] = $kvClient->DeleteRange($request);
            if ($status !== 0) {
                echo "Error#{$status}: {$reply}\n";
                break;
            }
        }
        $kvClient->close();
    });
});
