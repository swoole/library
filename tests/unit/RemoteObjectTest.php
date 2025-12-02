<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function Swoole\Coroutine\run;

/**
 * @internal
 */
#[CoversClass(RemoteObject::class)]
#[CoversClass(RemoteObject\Server::class)]
#[CoversClass(RemoteObject\Client::class)]
class RemoteObjectTest extends TestCase
{
    public function testCallFunction(): void
    {
        run(function () {
            $client = new RemoteObject\Client('127.0.0.1', 9501);
            $this->assertEquals($client->call('php_uname', 'm'), 'x86_64');
            $gd_info = $client->call('gd_info');
            $this->assertIsArray($gd_info);
            $this->assertGreaterThanOrEqual(10, count($gd_info));
        });
    }
}
