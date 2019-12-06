<?php
declare(strict_types=1);

if (!defined('SWOOLE_LIBRARY')) {
    require __DIR__ . '/../vendor/autoload.php';
}

if (!defined('MYSQL_SERVER_HOST')) {
    define('MYSQL_SERVER_HOST', '127.0.0.1');
    define('MYSQL_SERVER_PORT', 3306);
    define('MYSQL_SERVER_USER', 'root');
    define('MYSQL_SERVER_PWD', 'root');
    define('MYSQL_SERVER_DB', 'test');
}

if (!defined('REDIS_SERVER_HOST')) {
    define('REDIS_SERVER_HOST', '127.0.0.1');
    define('REDIS_SERVER_PORT', 6379);
}
