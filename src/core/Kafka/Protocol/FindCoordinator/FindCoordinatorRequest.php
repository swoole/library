<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\FindCoordinator;

use Swoole\Kafka\Protocol\AbstractRequest;
use Swoole\Kafka\Protocol\ProtocolField;

class FindCoordinatorRequest extends AbstractRequest
{
    /**
     * The coordinator key.
     *
     * @var string
     */
    protected $key = '';

    /**
     * The coordinator key type.  (Group, transaction, etc.).
     *
     * @var int
     */
    protected $keyType = 0;

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('key', 'string', false, [0, 1, 2, 3], [3], [], [], null),
                new ProtocolField('keyType', 'int8', false, [1, 2, 3], [3], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getRequestApiKey(): ?int
    {
        return 10;
    }

    public function getMaxSupportedVersion(): int
    {
        return 3;
    }

    public function getFlexibleVersions(): array
    {
        return [3];
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function getKeyType(): int
    {
        return $this->keyType;
    }

    public function setKeyType(int $keyType): self
    {
        $this->keyType = $keyType;

        return $this;
    }
}
