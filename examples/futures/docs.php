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
use Swoole\Coroutine\Http;
use Swoole\Future;

require_once __DIR__ . '/../bootstrap.php';

\Co\run(static function (): void {
    $future = Future\async(static function (): void {
        echo "are lazy\n";
    });

    Coroutine::create(static function (): void {
        echo 'Futures ';
    });

    echo $future->await();

    $future = Future\async(static function (): string {
        return Http\get('https://httpbin.org/get')->getBody();
    });

    echo $future->await();

    $future = Future\async(static function (): string {
        throw new RuntimeException('Futures propagates exceptions');
    });

    try {
        $future->await();
    } catch (Throwable $e) {
        echo $e->getMessage();
    }
});
