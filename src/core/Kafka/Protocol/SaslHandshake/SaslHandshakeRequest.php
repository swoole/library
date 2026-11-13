<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\SaslHandshake;

use Swoole\Kafka\Protocol\AbstractRequest;
use Swoole\Kafka\Protocol\ProtocolField;

class SaslHandshakeRequest extends AbstractRequest
{
    /**
     * The SASL mechanism chosen by the client.
     *
     * @var string
     */
    protected $mechanism = '';

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('mechanism', 'string', false, [0, 1], [], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getRequestApiKey(): ?int
    {
        return 17;
    }

    public function getMaxSupportedVersion(): int
    {
        return 1;
    }

    public function getFlexibleVersions(): array
    {
        return [];
    }

    public function getMechanism(): string
    {
        return $this->mechanism;
    }

    public function setMechanism(string $mechanism): self
    {
        $this->mechanism = $mechanism;

        return $this;
    }
}
