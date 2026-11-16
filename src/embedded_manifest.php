<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

return [
    'name'            => 'swoole',
    'checkFileChange' => !getenv('SWOOLE_LIBRARY_DEV'),
    'output'          => getenv('SWOOLE_DIR') . '/ext-src/php_swoole_library.h',
    'stripComments'   => false,
    /*
     * Composer-only modules: loaded via the "Swoole\\" PSR-4 autoload rule, NOT compiled into the
     * extension header. make-library skips these paths in its "Some files are not loaded" check
     * (see swoole/make-library). Do not add them to "files" below; they must keep being autoloaded.
     * A prefix covers the path itself and its directory only, so the standalone core/FastCGI.php
     * is listed separately from the core/FastCGI/ tree.
     */
    'ignore' => [
        'core/Grpc',
        'core/Debugger',
        'core/Proxy',
        'core/Kafka',
        'core/FastCGI.php',
        'core/FastCGI',
        'core/Coroutine/FastCGI',
    ],
    /* Notice: Sort by dependency */
    'files' => [
        # <basic> #
        'constants.php',
        # <std> #
        'std/exec.php',
        # <core> #
        'core/Constant.php',
        'core/StringObject.php',
        'core/MultibyteStringObject.php',
        'core/Exception/ArrayKeyNotExists.php',
        'core/ArrayObject.php',
        'core/ObjectProxy.php',
        'core/Coroutine/WaitGroup.php',
        'core/Coroutine/Server.php',
        'core/Coroutine/Server/Connection.php',
        'core/Coroutine/Barrier.php',
        'core/Coroutine/Http/ClientProxy.php',
        'core/Coroutine/Http/functions.php',
        # <core for Http2> #
        'core/Coroutine/Http2/MultiplexClient.php',
        # <core for connection pool> #
        'core/ConnectionPool.php',
        'core/Database/ObjectProxy.php',
        'core/Database/MysqliConfig.php',
        'core/Database/MysqliException.php',
        'core/Database/MysqliPool.php',
        'core/Database/MysqliProxy.php',
        'core/Database/MysqliStatementProxy.php',
        'core/Database/DetectsLostConnections.php',
        'core/Database/PDOConfig.php',
        'core/Database/PDOPool.php',
        'core/Database/PDOProxy.php',
        'core/Database/PDOStatementProxy.php',
        'core/Database/RedisConfig.php',
        'core/Database/RedisPool.php',
        # <core for HTTP> #
        'core/Http/Status.php',
        # <core for cURL> #
        'core/Curl/Exception.php',
        'core/Curl/Handler.php',
        # <core for Process> #
        'core/Process/Manager.php',
        # <core for RemoteObject> #
        'core/RemoteObject.php',
        'core/RemoteObject/Server.php',
        'core/RemoteObject/Context.php',
        'core/RemoteObject/Client.php',
        'core/RemoteObject/Exception.php',
        'core/RemoteObject/ProxyTrait.php',
        # <core for Server> #
        'core/Server/Admin.php',
        'core/Server/Helper.php',
        # <core for NameResolver> #
        'core/NameResolver.php',
        'core/NameResolver/Exception.php',
        'core/NameResolver/Cluster.php',
        'core/NameResolver/Redis.php',
        'core/NameResolver/Nacos.php',
        'core/NameResolver/Registration.php',
        'core/NameResolver/ServiceRegistration.php',
        'core/NameResolver/Consul.php',
        # <core for Thread> #
        'core/Thread/Pool.php',
        'core/Thread/Runnable.php',
        # <core for functions> #
        'core/Coroutine/functions.php',
        # <ext> #
        'ext/curl.php',
        'ext/sockets.php',
        'ext/standard.php',
        'ext/mongodb.php',
        # <finalizer> #
        'functions.php',
        'alias.php',
    ],
];
