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
} else {
    define('CONSUL_AGENT_URL', 'http://127.0.0.1:8500');
    define('NACOS_SERVER_URL', 'http://127.0.0.1:8848');
    define('REDIS_SERVER_URL', 'tcp://127.0.0.1:6379');
    define('GITHUB_ACTIONS', false);
}

// This points to folder ./tests/www under root directory of the project.
const DOCUMENT_ROOT = '/var/www/tests/www';

$process = new Swoole\Process(function (Swoole\Process $process) {
    include dirname(__DIR__) . '/examples/remote-object/server.php';
});
$process->start();

register_shutdown_function(function () use ($process) {
    Swoole\Process::kill($process->pid);
});