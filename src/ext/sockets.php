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

function swoole_socket_create(int $domain, int $type, int $protocol): Socket|false
{
    /* Native socket_create() reports failure by returning false, not by throwing; it also
     * emits a warning, so surface the swallowed message the same way. */
    try {
        return new Socket($domain, $type, $protocol);
    } catch (Socket\Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);
        return false;
    }
}

function swoole_socket_connect(Socket $socket, string $address, int $port = 0): bool
{
    return $socket->connect($address, $port);
}

function swoole_socket_read(Socket $socket, int $length, int $type = PHP_BINARY_READ): string|false
{
    if ($type != PHP_BINARY_READ) {
        return $socket->recvLine($length);
    }
    return $socket->recv($length);
}

function swoole_socket_write(Socket $socket, string $buffer, int $length = 0): int|false
{
    if ($length > 0 && $length < strlen($buffer)) {
        $buffer = substr($buffer, 0, $length);
    }
    return $socket->send($buffer);
}

function swoole_socket_send(Socket $socket, string $buffer, int $length, int $flags): int|false
{
    if ($flags != 0) {
        throw new RuntimeException("\$flags[{$flags}] is not supported");
    }
    return swoole_socket_write($socket, $buffer, $length);
}

/**
 * @param-out string|null $buffer
 */
function swoole_socket_recv(Socket $socket, mixed &$buffer, int $length, int $flags): int|false
{
    if ($flags & MSG_OOB) {
        throw new RuntimeException('\$flags[MSG_OOB] is not supported');
    }
    if ($flags & MSG_PEEK) {
        /* MSG_PEEK is not truly supported: the peeked result is intentionally discarded, and the
         * recv*() call below consumes the data, unlike native socket_recv() where MSG_PEEK leaves
         * the data in the socket buffer. This behavior is kept for backward compatibility. */
        $socket->peek($length);
    }
    $timeout = $flags & MSG_DONTWAIT ? 0.001 : 0;
    if ($flags & MSG_WAITALL) {
        $data = $socket->recvAll($length, $timeout);
    } else {
        $data = $socket->recv($length, $timeout);
    }
    if ($data === false) {
        $buffer = null;
        return false;
    }
    $buffer = $data;
    return strlen($buffer);
}

function swoole_socket_sendto(Socket $socket, string $buffer, int $length, int $flags, string $addr, int $port = 0): int|false
{
    if ($flags != 0) {
        throw new RuntimeException("\$flags[{$flags}] is not supported");
    }
    if ($socket->type != SOCK_DGRAM) {
        throw new RuntimeException('only supports dgram type socket');
    }
    if ($length > 0 && $length < strlen($buffer)) {
        $buffer = substr($buffer, 0, $length);
    }
    return $socket->sendto($addr, $port, $buffer);
}

function swoole_socket_recvfrom(Socket $socket, mixed &$buffer, int $length, int $flags, mixed &$name, mixed &$port = null): int|false
{
    if ($flags != 0) {
        throw new RuntimeException("\$flags[{$flags}] is not supported");
    }
    if ($length == 0) {
        $socket->errCode = SOCKET_EAGAIN;
        return false;
    }
    if ($socket->type != SOCK_DGRAM) {
        throw new RuntimeException('only supports dgram type socket');
    }
    $data = $socket->recvfrom($peer);
    if ($data === false) {
        return false;
    }
    $name = $peer['address'];
    if (func_num_args() == 6) {
        $port = $peer['port'];
    }
    if ($length < strlen($data)) {
        $buffer = substr($data, 0, $length);
    } else {
        $buffer = $data;
    }
    return strlen($buffer);
}

function swoole_socket_bind(Socket $socket, string $address, int $port = 0): bool
{
    return $socket->bind($address, $port);
}

function swoole_socket_listen(Socket $socket, int $backlog = 0): bool
{
    return $socket->listen($backlog);
}

function swoole_socket_create_listen(int $port, int $backlog = 128): Socket|false
{
    /* Native socket_create_listen() reports failure by returning false, not by throwing; it
     * also emits a warning, so surface the swallowed message the same way. */
    try {
        $socket = new Socket(AF_INET, SOCK_STREAM, SOL_TCP);
    } catch (Socket\Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);
        return false;
    }
    if (!$socket->bind('0.0.0.0', $port)) {
        return false;
    }
    if (!$socket->listen($backlog)) {
        return false;
    }
    return $socket;
}

function swoole_socket_accept(Socket $socket): Socket|false
{
    return $socket->accept();
}

function swoole_socket_getpeername(Socket $socket, mixed &$address, mixed &$port = null): bool
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

function swoole_socket_getsockname(Socket $socket, mixed &$address, mixed &$port = null): bool
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

function swoole_socket_set_option(Socket $socket, int $level, int $optname, mixed $optval): bool
{
    return $socket->setOption($level, $optname, $optval);
}

function swoole_socket_setopt(Socket $socket, int $level, int $optname, mixed $optval): bool
{
    return $socket->setOption($level, $optname, $optval);
}

function swoole_socket_get_option(Socket $socket, int $level, int $optname): mixed
{
    return $socket->getOption($level, $optname);
}

function swoole_socket_getopt(Socket $socket, int $level, int $optname): mixed
{
    return $socket->getOption($level, $optname);
}

function swoole_socket_shutdown(Socket $socket, int $how = 2): bool
{
    return $socket->shutdown($how);
}

function swoole_socket_close(Socket $socket): void
{
    $socket->close();
}

function swoole_socket_clear_error(?Socket $socket = null): void
{
    if ($socket) {
        $socket->errCode = 0;
    }
    swoole_clear_error();
}

function swoole_socket_last_error(?Socket $socket = null): int
{
    if ($socket) {
        return $socket->errCode;
    }
    return swoole_last_error();
}

function swoole_socket_set_block(Socket $socket): bool
{
    if ($socket->isClosed()) {
        return false;
    }
    if ($socket->__ext_sockets_nonblock) {
        $socket->setOption(SOL_SOCKET, SO_RCVTIMEO, $socket->__ext_sockets_timeout);
    }
    $socket->__ext_sockets_nonblock = false;
    return true;
}

function swoole_socket_set_nonblock(Socket $socket): bool
{
    if ($socket->isClosed()) {
        return false;
    }
    if ($socket->__ext_sockets_nonblock) {
        return true;
    }
    $socket->__ext_sockets_nonblock = true;
    $socket->__ext_sockets_timeout  = $socket->getOption(SOL_SOCKET, SO_RCVTIMEO);
    $socket->setOption(SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 1000]);
    return true;
}

function swoole_socket_create_pair(
    int $domain,
    int $type,
    int $protocol,
    mixed &$pair,
): bool {
    $_pair = swoole_coroutine_socketpair($domain, $type, $protocol);
    if ($_pair) {
        $pair = $_pair;
        return true;
    }
    return false;
}

/**
 * @since 5.0.0
 */
function swoole_socket_import_stream(mixed $stream): Socket|false
{
    return Socket::import($stream);
}
