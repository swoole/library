<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\NameResolver;

use Swoole\Coroutine\Http\ClientProxy;
use Swoole\NameResolver;
use Swoole\Runtime;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\NameResolver
 */
class TransportCompatibilityTest extends TestCase
{
    public function testBlockingAndCoroutineRequestsPreserveCredentialsAndHeaderTypes(): void
    {
        $code = <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:0');
            echo stream_socket_get_name($server, false), "\n"; flush();
            $requests = [];
            for ($i = 0; $i < 2; $i++) {
                $peer = stream_socket_accept($server, 3);
                if (!$peer) { exit(1); }
                stream_set_timeout($peer, 2);
                $request = '';
                while (!str_contains($request, "\r\n\r\n")) {
                    $data = fread($peer, 4096);
                    if ($data === '' || $data === false) { exit(2); }
                    $request .= $data;
                }
                $requests[] = $request;
                fwrite($peer, "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: 2\r\nX-Probe: value\r\n\r\nok");
                fclose($peer);
            }
            fclose($server);
            echo json_encode($requests, JSON_THROW_ON_ERROR);
            PHP;
        $flags = Runtime::getHookFlags();
        Runtime::setHookFlags(0);
        $process = null;
        try {
            $process = proc_open([PHP_BINARY, '-d', 'swoole.enable_library=Off', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            stream_set_timeout($pipes[1], 4);
            $address = trim(fgets($pipes[1]));
            self::assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $address);
            $resolver = new class('http://user:p%40ss@' . $address . '/base') extends NameResolver {
                public function join(string $name, string $ip, int $port, array $options = []): bool
                {
                    return true;
                }

                public function leave(string $name, string $ip, int $port): bool
                {
                    return true;
                }

                public function getCluster(string $name): ?Cluster
                {
                    return null;
                }

                public function fetch(): ClientProxy
                {
                    return $this->request('GET', '/probe');
                }
            };
            $blocking  = $resolver->fetch();
            $coroutine = null;
            self::coRun(static function () use ($resolver, &$coroutine): void { $coroutine = $resolver->fetch(); });
            foreach ([$blocking, $coroutine] as $response) {
                self::assertSame('ok', $response->getBody());
                self::assertSame(200, $response->getStatusCode());
                self::assertSame('value', $response->getHeaders()['x-probe']);
            }
            $requests = json_decode(stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            foreach ($requests as $request) {
                self::assertStringContainsString('Authorization: Basic ' . base64_encode('user:p@ss'), $request);
                self::assertStringContainsString('GET /base/probe HTTP/1.1', $request);
            }
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertSame('', $error);
            $process = null;
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            Runtime::setHookFlags($flags);
        }
    }
}
