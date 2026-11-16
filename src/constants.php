<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

define('SWOOLE_LIBRARY', true);

// Defaults for RemoteObject\Server and the daemon started by swoole_init_default_remote_object_server().
// Standard hooks call these five functions; the MongoDB hook creates MongoDB\Client remotely.
// DNS aliases use the same RPC targets, while gethostbynamel() uses Coroutine\System::getaddrinfo().
// MongoDB database/collection/cursor objects are returned as references, not created through Client::create().
// Applications can override either list with default_remote_object_server_options; [] allows all.
!defined('SWOOLE_LIBRARY_REMOTE_OBJECT_CONFIG') && define('SWOOLE_LIBRARY_REMOTE_OBJECT_CONFIG', [
    'allowed_functions' => [
        'mail',
        'checkdnsrr',
        'swoole_real_getmxrr',
        'swoole_real_dns_get_record',
        'gethostbyaddr',
    ],
    'allowed_classes' => [
        'MongoDB\Client',
    ],
]);

// PHP 8.2 and 8.3 build with libcurl 7.29.0 or later, and ext-curl defines these three from libcurl 7.37.0
// (the first two) and 7.40.0 (the last one) on. PHP 8.4 requires libcurl 7.61.0 and always has them.
!defined('CURLOPT_HEADEROPT') && define('CURLOPT_HEADEROPT', 229);
!defined('CURLOPT_PROXYHEADER') && define('CURLOPT_PROXYHEADER', 10228);
!defined('CURLOPT_UNIX_SOCKET_PATH') && define('CURLOPT_UNIX_SOCKET_PATH', 10231);
// Do not define these in the global namespace: native curl clients use their presence for feature detection.
!defined('CURLOPT_PREREQFUNCTION') && define('Swoole\Curl\CURLOPT_PREREQFUNCTION', 20312);
!defined('CURL_PREREQFUNC_OK') && define('Swoole\Curl\CURL_PREREQFUNC_OK', 0);
!defined('CURL_PREREQFUNC_ABORT') && define('Swoole\Curl\CURL_PREREQFUNC_ABORT', 1);
