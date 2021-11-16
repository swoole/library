<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Coroutine;

Coroutine::set([
    'log_level' => SWOOLE_LOG_INFO,
    'trace_flags' => 0,
]);

if (!defined('SWOOLE_LIBRARY')) {
    require __DIR__ . '/../vendor/autoload.php';
}

if (!defined('MYSQL_SERVER_HOST')) {
    define('MYSQL_SERVER_HOST', 'mysql');
    define('MYSQL_SERVER_PORT', 3306);
    define('MYSQL_SERVER_USER', 'root');
    define('MYSQL_SERVER_PWD', 'password');
    define('MYSQL_SERVER_DB', 'test');
}

if (!defined('REDIS_SERVER_HOST')) {
    define('REDIS_SERVER_HOST', 'redis');
    define('REDIS_SERVER_PORT', 6379);
}

if (getenv('GITHUB_ACTIONS')) {
    define('CONSUL_AGENT_URL', 'http://consul:8500');
    define('NACOS_SERVER_URL', 'http://nacos:8848');
    define('REDIS_SERVER_URL', 'tcp://redis:6379');
} else {
    define('CONSUL_AGENT_URL', 'http://127.0.0.1:8500');
    define('NACOS_SERVER_URL', 'http://127.0.0.1:8848');
    define('REDIS_SERVER_URL', 'tcp://127.0.0.1:6379');
}
