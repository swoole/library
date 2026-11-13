<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\WriteTxnMarkers;

use Swoole\Kafka\Protocol\AbstractResponse;
use Swoole\Kafka\Protocol\ProtocolField;

class WriteTxnMarkersResponse extends AbstractResponse
{
    /**
     * The results for writing makers.
     *
     * @var WritableTxnMarkerResult[]
     */
    protected $markers = [];

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('markers', WritableTxnMarkerResult::class, true, [0], [], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getRequestApiKey(): ?int
    {
        return 27;
    }

    public function getFlexibleVersions(): array
    {
        return [];
    }

    /**
     * @return WritableTxnMarkerResult[]
     */
    public function getMarkers(): array
    {
        return $this->markers;
    }

    /**
     * @param WritableTxnMarkerResult[] $markers
     */
    public function setMarkers(array $markers): self
    {
        $this->markers = $markers;

        return $this;
    }
}
