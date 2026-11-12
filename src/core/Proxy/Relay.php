<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Proxy;

use Swoole\Client;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Swoole\Coroutine\WaitGroup;

/**
 * Copy bytes both ways between two connected sockets.
 *
 * A clean end of one direction shuts down writing on the other socket and leaves its reading
 * direction open, so a peer that half-closes still receives the reply. An error or a timeout
 * closes both sockets, which unblocks the other direction instead of leaving it waiting.
 */
final class Relay
{
    private const CHUNK = 65536;

    public static function bridge(Socket $left, Socket $right, float $timeout): void
    {
        $closed = false;
        $abort  = static function () use (&$closed, $left, $right): void {
            if ($closed) {
                return;
            }
            $closed = true;
            $left->close();
            $right->close();
        };
        $group = new WaitGroup(2);
        $copy  = static function (Socket $from, Socket $to) use ($group, $timeout, $abort): void {
            try {
                while (true) {
                    $data = $from->recv(self::CHUNK, $timeout);
                    if ($data === '') {
                        $to->shutdown(Client::SHUT_WR);

                        return;
                    }
                    if (!is_string($data) || !self::sendAll($to, $data, $timeout)) {
                        $abort();

                        return;
                    }
                }
            } finally {
                $group->done();
            }
        };
        if (Coroutine::create($copy, $left, $right) === false) {
            $group->done();
            $abort();
        }
        if (Coroutine::create($copy, $right, $left) === false) {
            $group->done();
            $abort();
        }
        $group->wait();
        $abort();
    }

    private static function sendAll(Socket $socket, string $data, float $timeout): bool
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $sent = $socket->sendAll(substr($data, $offset), $timeout);
            if (!is_int($sent) || $sent <= 0) {
                return false;
            }
            $offset += $sent;
        }

        return true;
    }
}
