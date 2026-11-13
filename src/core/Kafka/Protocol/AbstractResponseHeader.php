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

class AbstractResponseHeader extends AbstractStruct
{
    public static function parseVersion(int $requestApiVersion, array $flexibleVersions, ?int $apiKey = null): int
    {
        // ApiVersions is how the client learns which versions are flexible, so the broker
        // always frames that response with a v0 header. KIP-511.
        if ($apiKey === ApiKeys::PROTOCOL_API_VERSIONS) {
            return 0;
        }

        return \in_array($requestApiVersion, $flexibleVersions) ? 1 : 0;
    }
}
