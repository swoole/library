<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\DescribeDelegationToken;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class DescribedDelegationTokenRenewer extends AbstractStruct
{
    /**
     * The renewer principal type.
     *
     * @var string
     */
    protected $principalType = '';

    /**
     * The renewer principal name.
     *
     * @var string
     */
    protected $principalName = '';

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('principalType', 'string', false, [0, 1, 2], [2], [], [], null),
                new ProtocolField('principalName', 'string', false, [0, 1, 2], [2], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [2];
    }

    public function getPrincipalType(): string
    {
        return $this->principalType;
    }

    public function setPrincipalType(string $principalType): self
    {
        $this->principalType = $principalType;

        return $this;
    }

    public function getPrincipalName(): string
    {
        return $this->principalName;
    }

    public function setPrincipalName(string $principalName): self
    {
        $this->principalName = $principalName;

        return $this;
    }
}
