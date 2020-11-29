<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Coroutine\Socket;

function swoole_socket_create(int $domain, int $type, int $protocol)
{
    return new Socket($domain, $type, $protocol);
}

function swoole_socket_connect(Socket $socket, string $address, int $port = 0)
{
    return $socket->connect($address, $port);
}

function swoole_socket_write(Socket $socket, string $buffer, int $length = 0): int
{
    if ($length > 0 and $length < strlen($buffer)) {
        $buffer = substr($buffer, 0, $length);
    }
    return $socket->send($buffer);
}

function swoole_socket_send(Socket $socket, string $buf, int $len, int $flags): int
{
    if ($flags != 0) {
        throw new RuntimeException("\$flags[{$flags}] is not supported");
    }
    return swoole_socket_write($socket, $buf, $len);
}

function swoole_socket_read(Socket $socket, int $length, int $type = PHP_BINARY_READ)
{
    if ($type != PHP_BINARY_READ) {
        throw new RuntimeException('PHP_NORMAL_READ type is not supported');
    }
    return $socket->recv($length);
}

function swoole_socket_recv(Socket $socket, &$buf, int $len, int $flags)
{
    if ($flags & MSG_OOB) {
        throw new RuntimeException('$flags[MSG_OOB] is not supported');
    }
    if ($flags & MSG_PEEK) {
        $buf = $socket->peek($len);
    }
    $timeout = $flags & MSG_DONTWAIT ? 0.001 : 0;
    if ($flags & MSG_WAITALL) {
        $buf = $socket->recvAll($len, $timeout);
    } else {
        $buf = $socket->recv($len, $timeout);
    }
    if ($buf == false) {
        return false;
    }
    return strlen($buf);
}

function swoole_socket_bind(Socket $socket, string $address, int $port = 0): bool
{
    return $socket->bind($address, $port);
}

function swoole_socket_listen(Socket $socket, int $backlog = 0): bool
{
    return $socket->listen($backlog);
}

function swoole_socket_create_listen(int $port, int $backlog = 128)
{
    $socket = new Socket(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$socket->bind('0.0.0.0', $port)) {
        return false;
    }
    if (!$socket->listen($backlog)) {
        return false;
    }
    return $socket;
}

function swoole_socket_accept(Socket $socket)
{
    return $socket->accept();
}

function swoole_socket_getpeername(Socket $socket, &$address, &$port = null)
{
    $info = $socket->getpeername();
    if (!$info) {
        return false;
    }
    $address = $info['address'];
    if (func_num_args() == 3) {
        $port = $info['port'];
    }
    return true;
}

function swoole_socket_getsockname(Socket $socket, &$address, &$port = null)
{
    $info = $socket->getsockname();
    if (!$info) {
        return false;
    }
    $address = $info['address'];
    if (func_num_args() == 3) {
        $port = $info['port'];
    }
    return true;
}

function swoole_socket_close(Socket $socket)
{
    $socket->close();
}
