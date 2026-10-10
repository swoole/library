<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

header('Content-Type: text/plain');
header('Link: </one>; rel=preload', false);
header('Link: </two>; rel=preload', false);
header('Set-Cookie: first=one', false);
header('Set-Cookie: second=two', false);
echo 'headers';
