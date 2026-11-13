<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\ResponseHeader;

use Swoole\Kafka\Protocol\AbstractResponseHeader;
use Swoole\Kafka\Protocol\ProtocolField;

class ResponseHeader extends AbstractResponseHeader
{
    /**
     * The correlation ID of this response.
     *
     * @var int
     */
    protected $correlationId = 0;

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('correlationId', 'int32', false, [0, 1], [1], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [1];
    }

    public function getCorrelationId(): int
    {
        return $this->correlationId;
    }

    public function setCorrelationId(int $correlationId): self
    {
        $this->correlationId = $correlationId;

        return $this;
    }
}
