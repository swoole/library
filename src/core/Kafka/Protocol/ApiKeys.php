<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol;

class ApiKeys extends AbstractApiKeys
{
    public static function createResponse(int $protocol, string $data = '', int $apiVersion = 0): AbstractResponse
    {
        $name = self::PROTOCOL_MAP[$protocol] ?? null;
        if (!$name) {
            throw new \RuntimeException(sprintf('Could not found api keys %d', $protocol));
        }
        $class = 'Swoole\Kafka\Protocol\\' . $name . '\\' . $name . 'Response';
        /** @var AbstractResponse $instance */
        $instance = new $class();
        if ($data) {
            $instance->unpack($data, $size, $apiVersion);
        }

        return $instance;
    }
}
