<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine;

use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Coroutine\Server
 */
class ServerTest extends TestCase
{
    public function testFatalAcceptFailureReturnsFalse(): void
    {
        $socket = new class {
            public int $errCode = SOCKET_ECONNRESET;

            public string $errMsg = 'Connection reset by peer';

            public function setProtocol(array $setting): bool
            {
                return true;
            }

            public function accept(): false
            {
                return false;
            }
        };

        $server = new class($socket) extends Server {
            public function __construct(object $socket)
            {
                $this->socket = $socket;
            }
        };
        $server->handle(static function (): void {});

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });

        try {
            $result = $server->start();
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);
        $this->assertSame(SOCKET_ECONNRESET, $server->errCode);
        $this->assertSame('accept failed, Error: Connection reset by peer[' . SOCKET_ECONNRESET . ']', $warning);
    }

    /**
     * An accept error that concerns one incoming connection only, such as the peer aborting the handshake, must
     * not stop the server from accepting the next connection.
     */
    public function testTransientAcceptFailureIsSkipped(): void
    {
        $socket = new class {
            public int $errCode = 0;

            public string $errMsg = '';

            public int $accepts = 0;

            /** @var int[] The error each accept() call fails with, in order; the last one stops the server. */
            public array $errCodes = [SOCKET_ECONNABORTED, SOCKET_ECONNABORTED, SOCKET_ECANCELED];

            public function setProtocol(array $setting): bool
            {
                return true;
            }

            public function accept(): false
            {
                $this->errCode = $this->errCodes[$this->accepts++];
                $this->errMsg  = 'accept error ' . $this->errCode;
                return false;
            }
        };

        $server = new class($socket) extends Server {
            public function __construct(object $socket)
            {
                $this->socket = $socket;
            }
        };
        $server->handle(static function (): void {});

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });

        try {
            $result = $server->start();
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($result, 'The server ran until it was stopped, not until the first aborted connection.');
        $this->assertSame(3, $socket->accepts, 'The server kept accepting past the two aborted connections.');
        $this->assertSame(0, $server->errCode);
        $this->assertNull($warning);
    }
}
