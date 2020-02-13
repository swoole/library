<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\FastCGI;

class PHPFPM
{
    public static function getDefaultAddressArray(): array
    {
        $fpmTT = `php-fpm -tt 2>&1`;
        if (preg_match('/listen = ([^\r\n]+)/', $fpmTT, $match)) {
            $listen = trim($match[1]);
            [$ip, $port] = explode(':', $listen, 2);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return [$ip, (int) $port];
            }
            return [$listen, 0];
        }
        return ['127.0.0.1', 9000];
    }

    public static function getDefaultAddress(): string
    {
        $address = static::getDefaultAddressArray();
        return $address[1] === 0 ? $address[0] : "{$address[0]}:{$address[1]}";
    }
}
