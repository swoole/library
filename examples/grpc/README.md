# Swoole-Grpc-Client

> 本目录是 `swoole/grpc` 合并进 `swoole/library` 后保留的示例与生成代码。核心运行时位于仓库的
> `src/core/Grpc/`，命名空间为 `Swoole\Grpc`，通过 Composer autoload 加载，**不会**编译进 Swoole
> 扩展。示例运行前需要 `google/protobuf`；重新生成桩代码需要 `protoc` 与 `grpc_php_plugin`。
> 示例统一 `require` 本目录的 `bootstrap.php`，服务器地址取自 `\Swoole\Grpc\Example\SERVER_URI`
> （可由环境变量 `GRPC_SERVER_DEFAULT_URI` 覆盖）。

[![Latest Version](https://img.shields.io/github/release/swoole/grpc-client.svg)](https://github.com/swoole/grpc-client/releases)
[![Php Version](https://img.shields.io/badge/php-%3E=7.1-brightgreen.svg?maxAge=2592000)](https://secure.php.net/)
[![Swoole Version](https://img.shields.io/badge/swoole-%3E=4.0.4-brightgreen.svg?maxAge=2592000)](https://github.com/swoole/swoole-src)
[![Swoole License](https://img.shields.io/hexpm/l/plug.svg?maxAge=2592000)](https://github.com/swoole/grpc-client/blob/master/LICENSE)

## Introduction

由Swoole驱动的Grpc协程客户端, 底层使用高性能协程Http2-Client客户端

- 同步代码几乎无改动
	- 自动**协程调度获得异步高性能**
	- 提供Grpc代码生成器Plus版, 0成本迁移
- 基于Channel实现的消息生产消费
  - **一个客户端连接**即可**同时**hold住**上万个**请求响应
  - 支持跨协程并发, 多类型Client分享同一连接
- **Etcd的直接支持**
	- 使用Http2协议全双工通信+Protobuf极限压缩, 告别同步阻塞与Json打包的低性能

<br>

## Requirement

- PHP7及以上
- [Swoole](https://github.com/swoole/swoole-src): v4.4.0及以上, StreamingCall支持需要v4.5.3及以上
- [Protobuf](https://github.com/google/protobuf/tree/master/php)
- [grpc_php_plugin](https://github.com/grpc/grpc/)
- 请不要启用grpc的php扩展, 也无需grpc的php库

<br>

## Usage

仓库已提供Etcd的生成代码, 如要自己根据proto文件生成代码, 请使用`tools`目录下的生成工具`generator`, 使用方法和`protoc`命令完全一样, 增强了支持以目录作为参数, 自动查找目录下的proto文件生成, 如: 该目录下已提供的`grpc`生成代码脚本:

```shell
# it's generate_grpc.sh
./generator \
--proto_path=./../proto \
--php_out=./../generated/Grpc \
--grpc_out=./../generated/Grpc \
--plugin=protoc-gen-grpc=$1 \
./../proto
```

只需要将proto文件放在`Grpc/Proto`下, 运行`./generate_grpc.sh ../../grpc/bins/opt/grpc_php_plugin` (参数是你的grpc php插件位置, 一般在`grpc/bins/opt`目录中), 即可生成相关代码

<br>

##  Examples

>  以下示例都可在`examples`目录下找到并直接运行

### Grpc

---

#### HelloWorld

经典的Grpc官方示例, 代码更加简洁

```php
$greeterClient = new GreeterClient('127.0.0.1:50051');
$request = new HelloRequest();
$request->setName('Swoole');
list($reply, $status) = $greeterClient->SayHello($request);
$message = $reply->getMessage();
echo "{$message}\n"; // Output: Hello Swoole
```



### Etcd

---

Etcd的几个基本操作的使用

#### Put

```php
use Swoole\Coroutine;

Coroutine::create(function () {
    $kvClient = new Etcdserverpb\KVClient(\Swoole\Grpc\Example\SERVER_URI);
    $request = new Etcdserverpb\PutRequest();
    $request->setPrevKv(true);
    $request->setKey('Hello');
    $request->setValue('Swoole');
    [$reply, $status] = $kvClient->Put($request);
    if ($status === 0) {
        echo "{$reply->getPrevKv()->getKey()}\n";
        echo "{$reply->getPrevKv()->getValue()}\n";
    } else {
        echo "Error#{$status}: {$reply}\n";
    }
    $kvClient->close();
});
```

#### Watch

> 创建一个协程负责Watch, 创建两个协程定时写入/删除键值以便观察效果

```php
use Etcdserverpb\WatchCreateRequest;
use Etcdserverpb\WatchCreateRequest\FilterType;
use Etcdserverpb\WatchRequest;
use Swoole\Coroutine;

// The Watcher
Coroutine::create(function () {
    $watchClient = new Etcdserverpb\WatchClient(\Swoole\Grpc\Example\SERVER_URI);

    $watchCall = $watchClient->Watch();
    $request = new WatchRequest();
    $createRequest = new WatchCreateRequest();
    $createRequest->setKey('Hello');
    $request->setCreateRequest($createRequest);

    _retry:
    $watchCall->push($request);
    /**@var $reply Etcdserverpb\WatchResponse */
    while (true) {
        [$reply, $status] = $watchCall->recv();
        if ($status === 0) { // success
            if ($reply->getCreated() || $reply->getCanceled()) {
                continue;
            }
            foreach ($reply->getEvents() as $event) {
                /**@var $event Mvccpb\Event */
                $type = $event->getType();
                $kv = $event->getKv();
                if (FilterType::NOPUT === $type) {
                    echo "Put key {$kv->getKey()} => {$kv->getValue()}\n";
                    break;
                } elseif (FilterType::NODELETE === $type) {
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
            } else {
                echo "Error#{$status}: {$reply}\n";
                break;
            }
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
```


#### Auth and Share Client

> 用户添加/展示/删除以及展示了如何让不同类型的EtcdClient能够使用同一个Swoole\Grpc\Client创建的连接

```php
use Swoole\Coroutine;

Coroutine::create(function () {
    $grpcClient = new \Swoole\Grpc\Client(\Swoole\Grpc\Example\SERVER_URI);
    // use in different type clients

    Coroutine::create(function () use ($grpcClient) {
        $kvClient = new Etcdserverpb\KVClient(\Swoole\Grpc\Example\SERVER_URI, ['use' => $grpcClient]);
        $request = new Etcdserverpb\PutRequest();
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
        $userNames = ['rango', 'twosee', 'gxh', 'stone', 'sjl'];
        foreach ($userNames as $username) {
            $userRequest->setName($username);
            [$reply, $status] = $authClient->UserAdd($userRequest);
            if ($status !== 0) {
                goto _error;
            }
        }

        $useListRequest = new Etcdserverpb\AuthUserListRequest();
        [$reply, $status] = $authClient->UserList($useListRequest);
        if ($status !== 0) {
            goto _error;
        }
        echo "\n=== SHOW USER LIST ===\n";
        foreach ($reply->getUsers() as $user) {
            /**@var \Authpb\User */
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
        var_dump(\Swoole\Grpc\Client::numStats());
        $grpcClient->close();
    });

});
```
