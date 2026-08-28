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
use Swoole\Coroutine;

if (!defined('SWOOLE_LIBRARY')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

Coroutine::set([
    Constant::OPTION_LOG_LEVEL   => SWOOLE_LOG_INFO,
    Constant::OPTION_TRACE_FLAGS => 0,
]);

if (!defined('MYSQL_SERVER_HOST')) {
    define('MYSQL_SERVER_HOST', 'mysql');
    define('MYSQL_SERVER_PORT', 3306);
    define('MYSQL_SERVER_USER', 'username');
    define('MYSQL_SERVER_PWD', 'password');
    define('MYSQL_SERVER_DB', 'test');
}

if (!defined('PGSQL_SERVER_HOST')) {
    define('PGSQL_SERVER_HOST', 'pgsql');
    define('PGSQL_SERVER_PORT', 5432);
    define('PGSQL_SERVER_USER', 'username');
    define('PGSQL_SERVER_PWD', 'password');
    define('PGSQL_SERVER_DB', 'test');
}

if (!defined('ORACLE_SERVER_HOST')) {
    define('ORACLE_SERVER_HOST', 'oracle');
    define('ORACLE_SERVER_PORT', 1521);
    define('ORACLE_SERVER_USER', 'system');
    define('ORACLE_SERVER_PWD', 'oracle');
    define('ORACLE_SERVER_DB', 'xe');
}

if (!defined('REDIS_SERVER_HOST')) {
    define('REDIS_SERVER_HOST', 'redis');
    define('REDIS_SERVER_PORT', 6379);
}

if (getenv('GITHUB_ACTIONS')) {
    define('CONSUL_AGENT_URL', 'http://consul:8500');
    define('NACOS_SERVER_URL', 'http://nacos:8848');
    define('REDIS_SERVER_URL', 'tcp://redis:6379');
    define('GITHUB_ACTIONS', true);
    define('MONGODB_SERVER_URL', 'mongodb://mongodb:27017');
} else {
    define('CONSUL_AGENT_URL', 'http://127.0.0.1:8500');
    define('NACOS_SERVER_URL', 'http://127.0.0.1:8848');
    define('REDIS_SERVER_URL', 'tcp://127.0.0.1:6379');
    define('GITHUB_ACTIONS', false);
    define('MONGODB_SERVER_URL', 'mongodb://127.0.0.1:27017');
}

// The httpbin service of docker-compose.yml: a local stand-in for httpbin.org (mccutchen/go-httpbin), reached
// as local.httpbin.org (a Docker link alias inside the app container; the Build Swoole workflow instead maps
// the name onto 127.0.0.1 in /etc/hosts and publishes the port via docker-compose.host.yml) on the default
// HTTP port so that URLs and Host headers look just like the httpbin.org ones. Note that go-httpbin reports
// request values (args, headers, form) as arrays of strings, where httpbin.org reports single values as
// plain strings.
define('HTTPBIN_SERVER_HOST', 'local.httpbin.org');
define('HTTPBIN_SERVER_PORT', 80);
define('HTTPBIN_SERVER_URL', 'http://' . HTTPBIN_SERVER_HOST);

// This points to folder ./tests/www under root directory of the project.
const DOCUMENT_ROOT = '/var/www/tests/www';

$remote_object_dir = dirname(__DIR__) . '/examples/remote-object';
swoole_library_set_option('default_remote_object_server_worker_num', 8);
swoole_library_set_option('default_remote_object_server_dir', $remote_object_dir);

// Swoole\Curl\Handler -- the library's own curl implementation, and what Curl\HandlerTest exercises -- is
// installed by SWOOLE_HOOK_CURL, which SWOOLE_HOOK_ALL leaves out: it is mutually exclusive with
// SWOOLE_HOOK_NATIVE_CURL, and the extension keeps the native one when both are asked for. Hook flags are
// process-wide and counit fixes them before the scheduler starts, so no test can turn this on for itself:
// a test flipping it mid-run breaks whatever concurrent test is holding a native handle at that moment.
// Swap it once, here, for the whole run. The fallback to SWOOLE_HOOK_ALL matters under plain PHPUnit, where
// nothing is hooked yet at this point; without it the run would end up with curl hooked and nothing else,
// because setting the flags here also settles the hook_flags option Swoole\Coroutine\run() would otherwise
// fill in with SWOOLE_HOOK_ALL. The cost is that Coroutine\Http's curl driver is exercised through
// Swoole\Curl\Handler rather than through native curl; the native path belongs to the extension, not here.
$hook_flags = Swoole\Runtime::getHookFlags() ?: SWOOLE_HOOK_ALL;
Swoole\Runtime::setHookFlags(($hook_flags & ~SWOOLE_HOOK_NATIVE_CURL) | SWOOLE_HOOK_CURL);
