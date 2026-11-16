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

use Swoole\Tests\TestCase;

/**
 * Runs serially because warning handlers are process-wide.
 *
 * @internal
 * @covers ::swoole_socket_accept
 * @covers ::swoole_socket_bind
 * @covers ::swoole_socket_clear_error
 * @covers ::swoole_socket_close
 * @covers ::swoole_socket_connect
 * @covers ::swoole_socket_create
 * @covers ::swoole_socket_create_pair
 * @covers ::swoole_socket_get_option
 * @covers ::swoole_socket_getopt
 * @covers ::swoole_socket_getpeername
 * @covers ::swoole_socket_getsockname
 * @covers ::swoole_socket_last_error
 * @covers ::swoole_socket_listen
 * @covers ::swoole_socket_read
 * @covers ::swoole_socket_recv
 * @covers ::swoole_socket_recvfrom
 * @covers ::swoole_socket_send
 * @covers ::swoole_socket_sendto
 * @covers ::swoole_socket_set_block
 * @covers ::swoole_socket_set_nonblock
 * @covers ::swoole_socket_set_option
 * @covers ::swoole_socket_setopt
 * @covers ::swoole_socket_shutdown
 * @covers ::swoole_socket_write
 */
class SocketsHookTest extends TestCase
{
    public function testStreamReadsPeekWaitallAndEof(): void
    {
        self::coRun(static function (): void {
            self::assertTrue(swoole_socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
            [$reader, $writer] = $pair;
            try {
                self::assertSame(4, swoole_socket_send($writer, 'abcd-more', 4, 0));
                self::assertSame(2, swoole_socket_recv($reader, $buffer, 2, MSG_PEEK));
                self::assertSame('ab', $buffer);
                self::assertSame(4, swoole_socket_recv($reader, $buffer, 4, MSG_WAITALL));
                self::assertSame('abcd', $buffer);
                self::assertSame(4, swoole_socket_write($writer, "a\r\nb"));
                self::assertSame("a\r", swoole_socket_read($reader, 8, PHP_NORMAL_READ));
                self::assertSame("\n", swoole_socket_read($reader, 8, PHP_NORMAL_READ));
                self::assertSame('b', swoole_socket_read($reader, 1));
                self::assertTrue(swoole_socket_shutdown($writer, 1));
                self::assertSame(0, swoole_socket_recv($reader, $buffer, 1, 0));
                self::assertNull($buffer);
            } finally {
                swoole_socket_close($reader);
                swoole_socket_close($writer);
            }
        });
    }

    public function testDontwaitOnStreamSendAndReceiveHasExplicitWarning(): void
    {
        self::coRun(function (): void {
            swoole_socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
            [$reader, $writer] = $pair;
            try {
                [$result, $warnings] = $this->captureWarnings(static fn () => swoole_socket_send($writer, 'abc', 3, MSG_DONTWAIT));
                self::assertSame(3, $result);
                self::assertCount(1, $warnings);
                self::assertStringContainsString('socket_send()', $warnings[0]);
                self::assertSame('abc', swoole_socket_read($reader, 3));
                [$result, $warnings] = $this->captureWarnings(static function () use ($reader, &$buffer) {
                    return swoole_socket_recv($reader, $buffer, 3, MSG_DONTWAIT);
                });
                self::assertFalse($result);
                self::assertNull($buffer);
                self::assertCount(1, $warnings);
                self::assertStringContainsString('1 ms timeout', $warnings[0]);
                self::assertSame(SOCKET_ETIMEDOUT, swoole_socket_last_error($reader));
                swoole_socket_clear_error($reader);
                self::assertSame(0, swoole_socket_last_error($reader));
            } finally {
                swoole_socket_close($reader);
                swoole_socket_close($writer);
            }
        });
    }

    public function testUdpDontwaitAndDatagramTruncation(): void
    {
        self::coRun(function (): void {
            $receiver = swoole_socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            $sender   = swoole_socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            try {
                self::assertTrue(swoole_socket_bind($receiver, '127.0.0.1', 0));
                self::assertTrue(swoole_socket_bind($sender, '127.0.0.1', 0));
                self::assertTrue(swoole_socket_getsockname($receiver, $address, $port));
                swoole_socket_getsockname($sender, $senderAddress, $senderPort);
                $original = ['sec' => 2, 'usec' => 500000];
                self::assertTrue(swoole_socket_setopt($sender, SOL_SOCKET, SO_SNDTIMEO, $original));
                [$result, $warnings] = $this->captureWarnings(static fn () => swoole_socket_sendto($sender, 'abcdef', 6, MSG_DONTWAIT, $address, $port));
                self::assertSame(6, $result);
                self::assertCount(1, $warnings);
                self::assertSame($original, swoole_socket_getopt($sender, SOL_SOCKET, SO_SNDTIMEO));
                self::assertSame(3, swoole_socket_recvfrom($receiver, $buffer, 3, 0, $peerAddress, $peerPort));
                self::assertSame('abc', $buffer);
                self::assertSame($senderAddress, $peerAddress);
                self::assertSame($senderPort, $peerPort);
                [$result, $warnings] = $this->captureWarnings(static function () use ($receiver, &$buffer, &$peerAddress, &$peerPort) {
                    return swoole_socket_recvfrom($receiver, $buffer, 3, MSG_DONTWAIT, $peerAddress, $peerPort);
                });
                self::assertFalse($result, 'The unread suffix of a datagram is discarded');
                self::assertSame('abc', $buffer, 'Like native recvfrom(), failure preserves its output variables');
                self::assertCount(1, $warnings);
            } finally {
                swoole_socket_close($receiver);
                swoole_socket_close($sender);
            }
        });
    }

    public function testTcpLifecycleAndSocketOptions(): void
    {
        self::coRun(static function (): void {
            $server = swoole_socket_create(AF_INET, SOCK_STREAM, 0);
            $client = swoole_socket_create(AF_INET, SOCK_STREAM, 0);
            $peer   = null;
            try {
                self::assertTrue(swoole_socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1));
                self::assertSame(1, swoole_socket_get_option($server, SOL_SOCKET, SO_REUSEADDR));
                self::assertTrue(swoole_socket_bind($server, '127.0.0.1', 0));
                self::assertTrue(swoole_socket_listen($server, 8));
                self::assertTrue(swoole_socket_getsockname($server, $address, $port));
                self::assertTrue(swoole_socket_connect($client, $address, $port));
                $peer = swoole_socket_accept($server);
                self::assertInstanceOf(\Swoole\Coroutine\Socket::class, $peer);
                self::assertTrue(swoole_socket_getpeername($client, $peerAddress, $peerPort));
                self::assertSame($address, $peerAddress);
                self::assertSame($port, $peerPort);
                self::assertSame(2, swoole_socket_write($client, 'hello', 2));
                self::assertSame('he', swoole_socket_read($peer, 2));
            } finally {
                if ($peer instanceof \Swoole\Coroutine\Socket) {
                    swoole_socket_close($peer);
                }
                swoole_socket_close($client);
                swoole_socket_close($server);
            }
        });
    }

    public function testBlockingModeRestoresItsOriginalTimeout(): void
    {
        self::coRun(function (): void {
            swoole_socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
            [$reader, $writer] = $pair;
            try {
                $original = ['sec' => 2, 'usec' => 500000];
                swoole_socket_set_option($reader, SOL_SOCKET, SO_RCVTIMEO, $original);
                [$result, $warnings] = $this->captureWarnings(static fn () => swoole_socket_set_nonblock($reader));
                self::assertTrue($result);
                self::assertCount(1, $warnings);
                self::assertTrue(property_exists($reader, '__ext_sockets_nonblock'));
                self::assertFalse(swoole_socket_recv($reader, $buffer, 1, 0));
                self::assertNull($buffer);
                self::assertTrue(swoole_socket_set_block($reader));
                self::assertSame($original, swoole_socket_get_option($reader, SOL_SOCKET, SO_RCVTIMEO));
            } finally {
                swoole_socket_close($reader);
                swoole_socket_close($writer);
            }
        });
    }

    public function testUnsupportedFlagsAreRejectedBeforeIo(): void
    {
        self::coRun(static function (): void {
            swoole_socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
            try {
                foreach (['send', 'recv', 'sendto', 'recvfrom'] as $operation) {
                    try {
                        match ($operation) {
                            'send'     => swoole_socket_send($pair[0], 'x', 1, MSG_OOB),
                            'recv'     => swoole_socket_recv($pair[0], $buffer, 1, MSG_OOB),
                            'sendto'   => swoole_socket_sendto($pair[0], 'x', 1, MSG_OOB, '127.0.0.1', 1),
                            'recvfrom' => swoole_socket_recvfrom($pair[0], $buffer, 1, MSG_OOB, $address),
                        };
                        self::fail('Unsupported flags must not be silently ignored');
                    } catch (\RuntimeException $e) {
                        self::assertStringContainsString('not supported', $e->getMessage());
                    }
                }
            } finally {
                swoole_socket_close($pair[0]);
                swoole_socket_close($pair[1]);
            }
        });
    }

    private function captureWarnings(callable $operation): array
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if (!str_contains($message, 'Swoole sockets hook differs')) {
                return false;
            }
            $warnings[] = $message;
            return true;
        });
        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }
        return [$result, $warnings];
    }
}
