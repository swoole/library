<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\RemoteObject;

use Swoole\Atomic\Long;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\Coroutine\Http\Server as HttpServer;
use Swoole\Tests\TestCase;

class WakeupProbe
{
    public static int $calls = 0;

    public function __wakeup(): void
    {
        self::$calls++;
    }
}

class DispatchProbe extends Server
{
    public bool $unexpected = false;

    protected function _maintenance(Context $context): void
    {
        $this->unexpected = true;
        $context->end(['code' => 0]);
    }
}

/**
 * @internal
 * @covers \Swoole\RemoteObject\Client
 * @covers \Swoole\RemoteObject\Context
 * @covers \Swoole\RemoteObject\Server
 */
class SecurityTest extends TestCase
{
    public function testServerRequiresAuthenticationConfiguration(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('nonempty api_key');
        new Server();
    }

    public function testOnlyProtocolReferencesCanBeUnserialized(): void
    {
        $before = WakeupProbe::$calls;
        try {
            Context::decode(serialize(['value' => new WakeupProbe()]));
            self::fail('Untrusted objects were accepted');
        } catch (Exception $e) {
            self::assertStringContainsString('not allowed', $e->getMessage());
        }
        self::assertSame($before, WakeupProbe::$calls);
        self::assertInstanceOf(\Swoole\RemoteObject::class, Context::decode(serialize(\Swoole\RemoteObject::marshal(1, 1, 'fixture'))));
        self::assertFalse(Context::decode('b:0;'));
        self::assertNull(Context::decode('N;'));
        $recursive         = [];
        $recursive['self'] = &$recursive;
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('nesting depth');
        Context::decode(serialize($recursive));
    }

    public function testAuthenticationDispatchAndRequestPayloads(): void
    {
        self::coRun(static function (): void {
            // Exercise the real HTTP handler without starting a process/thread worker pool.
            $rpc = (new \ReflectionClass(DispatchProbe::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(Server::class, 'apiKey'))->setValue($rpc, '0');
            (new \ReflectionProperty(Server::class, 'allowedFunctions'))->setValue($rpc, ['strlen' => true]);
            (new \ReflectionProperty(Server::class, 'allowedClasses'))->setValue($rpc, ['stdClass' => true]);
            (new \ReflectionProperty(Server::class, 'nextObjectId'))->setValue($rpc, new Long(1));
            $http = new HttpServer('127.0.0.1', 0);
            $http->handle('/', $rpc->onRequest(...));
            Coroutine::create(static function () use ($http): void { $http->start(); });
            $client = new HttpClient('127.0.0.1', $http->port);
            try {
                foreach (['', 'wrong'] as $key) {
                    $client->setHeaders(['x-api-key' => $key]);
                    self::assertTrue($client->post('/ping', []));
                    self::assertSame(403, $client->statusCode);
                }
                $client->setHeaders(['x-api-key' => '0', 'client-id' => 'probe', 'coroutine-id' => '1']);
                foreach (['start', 'onStart', 'releaseConnection', 'onRequest', '_construct', 'maintenance', 'ping/extra'] as $path) {
                    self::assertTrue($client->post('/' . $path, []));
                    self::assertSame(-1, Context::decode($client->body)['code'], $path);
                }
                self::assertFalse($rpc->unexpected);
                self::assertTrue($client->post('/call_function', ['function' => 'strlen', 'args' => serialize(['abc'])]));
                self::assertSame(3, Context::decode($client->body)['result']);
                self::assertTrue($client->post('/call_function', ['function' => 'phpversion', 'args' => serialize([])]));
                self::assertStringContainsString('not allowed', Context::decode($client->body)['exception']['message']);
                $before = WakeupProbe::$calls;
                self::assertTrue($client->post('/call_function', ['function' => 'strlen', 'args' => serialize([new WakeupProbe()])]));
                self::assertStringContainsString('not allowed', Context::decode($client->body)['exception']['message']);
                self::assertSame($before, WakeupProbe::$calls);
                self::assertTrue($client->post('/call_function', ['function' => 'strlen', 'args' => 'not serialized']));
                self::assertStringContainsString('Malformed', Context::decode($client->body)['exception']['message']);
            } finally {
                $client->close();
                $http->shutdown();
            }
        });
    }

    public function testClientRejectsObjectInjectionInResponses(): void
    {
        self::coRun(static function (): void {
            $http = new HttpServer('127.0.0.1', 0);
            $body = serialize(['code' => 0, 'result' => [new WakeupProbe()]]);
            $http->handle('/', static function ($request, $response) use ($body): void { $response->end($body); });
            Coroutine::create(static function () use ($http): void { $http->start(); });
            $before = WakeupProbe::$calls;
            try {
                (new Client('127.0.0.1', $http->port))->call('strlen', 'hello');
                self::fail('Untrusted response objects were accepted');
            } catch (Exception $e) {
                self::assertStringContainsString('not allowed', $e->getMessage());
                self::assertSame($before, WakeupProbe::$calls);
            } finally {
                $http->shutdown();
            }
        });
    }
}
