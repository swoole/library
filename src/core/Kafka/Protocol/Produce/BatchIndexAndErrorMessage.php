<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\Produce;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class BatchIndexAndErrorMessage extends AbstractStruct
{
    /**
     * The batch index of the record that cause the batch to be dropped.
     *
     * @var int
     */
    protected $batchIndex = 0;

    /**
     * The error message of the record that caused the batch to be dropped.
     *
     * @var string|null
     */
    protected $batchIndexErrorMessage;

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('batchIndex', 'int32', false, [8], [], [], [], null),
                new ProtocolField('batchIndexErrorMessage', 'string', false, [8], [], [8], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [];
    }

    public function getBatchIndex(): int
    {
        return $this->batchIndex;
    }

    public function setBatchIndex(int $batchIndex): self
    {
        $this->batchIndex = $batchIndex;

        return $this;
    }

    public function getBatchIndexErrorMessage(): ?string
    {
        return $this->batchIndexErrorMessage;
    }

    public function setBatchIndexErrorMessage(?string $batchIndexErrorMessage): self
    {
        $this->batchIndexErrorMessage = $batchIndexErrorMessage;

        return $this;
    }
}
