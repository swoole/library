<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/examples/bootstrap.php';

// This points to folder ./tests/www under root directory of the project.
define('DOCUMENT_ROOT', '/var/www/tests/www');

const CONSUL_AGENT_URL = 'http://127.0.0.1:8500';
const NACOS_SERVER_URL = 'http://127.0.0.1:8848';
const REDIS_SERVER_URL = "tcp://127.0.0.1:6379";
