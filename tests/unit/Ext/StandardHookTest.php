<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Ext;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Runtime;
use Swoole\Tests\TestCase;

/**
 * Uses an RPC stub: mail and DNS calls are inspected without sending mail or querying external DNS.
 *
 * @internal
 * @covers ::swoole_dns_get_mx
 * @covers ::swoole_dns_get_record
 * @covers ::swoole_getmxrr
 * @covers ::swoole_mail
 * @covers ::swoole_real_dns_get_record
 */
class StandardHookTest extends TestCase
{
    public function testMailAndDnsForwardNativeArgumentsAndOutputs(): void
    {
        self::coRun(static function (): void {
            $path   = sys_get_temp_dir() . '/sw-hook-' . bin2hex(random_bytes(5)) . '.sock';
            $server = new Server('unix:' . $path, 0);
            $calls  = [];
            $server->handle('/call_function', static function ($request, $response) use (&$calls): void {
                $function = $request->post['function'];
                $args     = unserialize($request->post['args'], ['allowed_classes' => false]);
                $calls[]  = [$function, $args];
                $result   = match ($function) {
                    'mail'                       => true,
                    'swoole_real_getmxrr'        => ['result' => true, 'host' => ['mx.example.invalid'], 'weight' => [10]],
                    'swoole_real_dns_get_record' => ['result' => [['type' => 16, 'data' => 'raw']], 'authoritative_name_servers' => ['authority'], 'additional_records' => ['additional']],
                    default                      => false,
                };
                $response->end(serialize(['code' => 0, 'result' => $result]));
            });
            Coroutine::create(static function () use ($server): void { $server->start(); });
            $initiated                                        = \SwooleLibrary::$remote_object_server_initiated;
            $socket                                           = \SwooleLibrary::$remote_object_server_socket_file;
            \SwooleLibrary::$remote_object_server_initiated   = true;
            \SwooleLibrary::$remote_object_server_socket_file = 'unix://' . $path;
            try {
                self::assertTrue(swoole_mail('review@example.invalid', 'subject', 'body', 'X-Review: yes', '-freview@example.invalid'));
                self::assertSame(['mail', ['review@example.invalid', 'subject', 'body', 'X-Review: yes', '-freview@example.invalid']], $calls[0]);
                self::assertTrue(swoole_mail(to: 'review@example.invalid', subject: 'subject', message: 'body', additional_headers: ['X-Review' => 'yes'], additional_params: ''));
                self::assertSame(['X-Review' => 'yes'], $calls[1][1][3]);
                self::assertTrue(swoole_getmxrr('example.invalid', $hosts));
                self::assertSame(['mx.example.invalid'], $hosts);
                $hosts   = false;
                $weights = 'old';
                self::assertTrue(swoole_dns_get_mx('example.invalid', $hosts, $weights));
                self::assertSame(['mx.example.invalid'], $hosts);
                self::assertSame([10], $weights);
                self::assertSame(['swoole_real_getmxrr', ['example.invalid']], $calls[2]);
                $authority  = static fn () => null; // An output variable's old value must not be serialized.
                $additional = false;
                self::assertSame([['type' => 16, 'data' => 'raw']], swoole_dns_get_record('example.invalid', 16, $authority, $additional, true));
                self::assertSame(['authority'], $authority);
                self::assertSame(['additional'], $additional);
                self::assertSame(['swoole_real_dns_get_record', ['example.invalid', 16, null, null, true]], $calls[4]);
            } finally {
                \SwooleLibrary::$remote_object_server_initiated   = $initiated;
                \SwooleLibrary::$remote_object_server_socket_file = $socket;
                $server->shutdown();
                @unlink($path);
            }
        });
    }

    public function testRawDnsTypeValidationReachesNativeResolver(): void
    {
        $flags = Runtime::getHookFlags();
        Runtime::setHookFlags(0);
        try {
            foreach ([[null, null], [[], null], [[], []]] as [$authority, $additional]) {
                try {
                    // An out-of-range raw type fails before any resolver/network operation is attempted.
                    swoole_real_dns_get_record('', DNS_ANY, $authority, $additional, true);
                    self::fail('The raw type must be validated by dns_get_record()');
                } catch (\ValueError $e) {
                    self::assertStringContainsString('must be between 1 and 65535', $e->getMessage());
                }
            }
        } finally {
            Runtime::setHookFlags($flags);
        }
    }
}
