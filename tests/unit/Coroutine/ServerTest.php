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
     * not stop the server from accepting the next connection. A run of such errors long enough to slow the server
     * down is reported, once.
     */
    public function testTransientAcceptFailureIsSkipped(): void
    {
        [$socket, $server] = self::getServerWithTransientAcceptFailures();

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });

        try {
            $result = $server->start();
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($result, 'The server ran until it was stopped, not until the first aborted connection.');
        $this->assertSame(33, $socket->accepts, 'The server kept accepting past the aborted connections.');
        $this->assertSame(0, $server->errCode);
        $error = 'accept error ' . SOCKET_ECONNABORTED . '[' . SOCKET_ECONNABORTED . ']';
        $this->assertSame(["accept keeps failing, Error: {$error}; the server goes on accepting, a millisecond apart"], $warnings);
    }

    /**
     * An error handler that turns warnings into exceptions, as many frameworks install, does not stop the server
     * through the warning about a run of transient accept errors.
     */
    public function testWarningAboutTransientAcceptFailuresThatThrows(): void
    {
        [$socket, $server] = self::getServerWithTransientAcceptFailures();

        // Only for the warnings of the server: the handler is process-wide, and other tests run at the same time.
        set_error_handler(static function (int $severity, string $message): bool {
            if (!str_starts_with($message, 'accept ')) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            $result = $server->start();
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($result);
        $this->assertSame(33, $socket->accepts, 'The server kept accepting past the warning.');
    }

    /**
     * A server over a socket whose accept() fails with SOCKET_ECONNABORTED 32 times in a row, twice as many as the
     * server skips without waiting, and then with SOCKET_ECANCELED, which stops the server.
     *
     * @return array{object, Server}
     */
    private static function getServerWithTransientAcceptFailures(): array
    {
        $socket = new class {
            public int $errCode = 0;

            public string $errMsg = '';

            public int $accepts = 0;

            /** @var int[] The error each accept() call fails with, in order; the last one stops the server. */
            public array $errCodes = [];

            public function __construct()
            {
                // Twice as many in a row as the server skips without waiting.
                $this->errCodes   = array_fill(0, 32, SOCKET_ECONNABORTED);
                $this->errCodes[] = SOCKET_ECANCELED;
            }

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

        return [$socket, $server];
    }
}
