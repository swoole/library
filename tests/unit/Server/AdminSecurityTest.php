<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Server;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Server;
use Swoole\Tests\TestCase;

class AdminServerProbe extends Server
{
    public function __construct()
    {
    }

    public function command(string $name, int $process_id, int $process_type, mixed $data, bool $json_decode = true): string|array|false
    {
        return '{"code":0,"data":"ok"}';
    }
}

class AdminStateProbe
{
    public static string $visible = 'public value';

    public string $instance = 'instance value';

    private static string $secret = 'private value';
}

/**
 * @internal
 * @covers \Swoole\Server\Admin
 */
class AdminSecurityTest extends TestCase
{
    public function testCredentialsAreRequired(): void
    {
        $server          = new AdminServerProbe();
        $server->setting = ['admin_server' => '127.0.0.1:0'];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires access_name:access_secret');
        Admin::start($server);
    }

    public function testTokenIsRequiredAndNoWildcardCorsIsAdvertised(): void
    {
        self::coRun(static function (): void {
            $server          = new AdminServerProbe();
            $server->setting = ['admin_server' => 'user:secret@127.0.0.1:0'];
            Coroutine::create(static function () use ($server): void { Admin::start($server); });
            $client = new Client('127.0.0.1', $server->admin_server->port);
            try {
                foreach (['', 'wrong', sha1('usersecret')] as $token) {
                    $client->setHeaders(['X-Admin-Server-Access-Token' => $token, 'Origin' => 'https://untrusted.invalid']);
                    self::assertTrue($client->get('/api/server_stats'));
                    self::assertSame($token === sha1('usersecret') ? 200 : 403, $client->statusCode);
                    self::assertArrayNotHasKey('access-control-allow-origin', $client->headers);
                }
                // A later failed listener must not clear this listener's captured credentials.
                $other          = new AdminServerProbe();
                $other->setting = ['admin_server' => '127.0.0.1:0'];
                try {
                    Admin::start($other);
                } catch (\InvalidArgumentException) {
                }
                $client->setHeaders([]);
                self::assertTrue($client->get('/api/server_stats'));
                self::assertSame(403, $client->statusCode);
            } finally {
                $client->close();
                $server->admin_server->shutdown();
            }
        });
    }

    public function testOnlyInitializedPublicStaticPropertiesCanBeInspected(): void
    {
        $server = new AdminServerProbe();
        foreach (['secret' => 4003, 'instance' => 4003, 'missing' => 4004, 'visible' => 0] as $property => $code) {
            $result = json_decode(Admin::handlerGetStaticPropertyValue($server, json_encode([
                'class_name' => AdminStateProbe::class, 'property_name' => $property,
            ], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($code, $result['code']);
            self::assertStringNotContainsString('private value', json_encode($result));
        }
    }
}
