<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

// PUT prevents PHP from consuming the whole body before the script starts.
usleep(1500000);
$body = file_get_contents('php://input');
header('Content-Type: application/json');
echo json_encode(['length' => strlen($body)]);
