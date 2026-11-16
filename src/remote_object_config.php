<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

/*
 * Default allow-lists for the remote object server that
 * swoole_init_default_remote_object_server() starts.
 *
 * The hooked standard functions in src/ext/standard.php forward to allowed_functions; the two
 * swoole_real_* helpers run on the server itself, so they have to be callable there too.
 * allowed_classes is what src/ext/mongodb.php creates through the client.
 * swoole_gethostbynamel() uses Coroutine\System::getaddrinfo() and never reaches the server.
 *
 * An application that uses swoole_get_default_remote_object_client() for its own remote objects
 * can override these with
 * swoole_library_set_option('default_remote_object_server_options', [
 *     'allowed_functions' => [...],
 *     'allowed_classes'   => [...],
 * ]);
 * Empty arrays restore the unrestricted behaviour.
 *
 * This is a separate configuration file, not a constant of RemoteObject\Server: it is packed
 * into the extension as an eval'd string, where a file that merely returned an array would lose
 * its value, so the data is exposed through a constant instead.
 */
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
