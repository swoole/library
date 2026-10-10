<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

http_response_code((int) ($_GET['code'] ?? 200));
header('Content-Type: text/plain');
echo 'status';
