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

abstract class AbstractRequest extends AbstractProtocol
{
    abstract public function getMaxSupportedVersion(): int;

    public function getApiKeyText(): string
    {
        return ApiKeys::PROTOCOL_MAP[$this->getRequestApiKey()] ?? 'Unknown';
    }
}
