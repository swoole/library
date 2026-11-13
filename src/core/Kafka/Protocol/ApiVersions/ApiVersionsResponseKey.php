<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\ApiVersions;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class ApiVersionsResponseKey extends AbstractStruct
{
    /**
     * The API index.
     *
     * @var int
     */
    protected $apiKey = 0;

    /**
     * The minimum supported version, inclusive.
     *
     * @var int
     */
    protected $minVersion = 0;

    /**
     * The maximum supported version, inclusive.
     *
     * @var int
     */
    protected $maxVersion = 0;

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('apiKey', 'int16', false, [0, 1, 2, 3], [3], [], [], null),
                new ProtocolField('minVersion', 'int16', false, [0, 1, 2, 3], [3], [], [], null),
                new ProtocolField('maxVersion', 'int16', false, [0, 1, 2, 3], [3], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [3];
    }

    public function getApiKey(): int
    {
        return $this->apiKey;
    }

    public function setApiKey(int $apiKey): self
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function getMinVersion(): int
    {
        return $this->minVersion;
    }

    public function setMinVersion(int $minVersion): self
    {
        $this->minVersion = $minVersion;

        return $this;
    }

    public function getMaxVersion(): int
    {
        return $this->maxVersion;
    }

    public function setMaxVersion(int $maxVersion): self
    {
        $this->maxVersion = $maxVersion;

        return $this;
    }
}
