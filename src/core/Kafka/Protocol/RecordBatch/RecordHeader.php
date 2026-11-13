<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\RecordBatch;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\Type\VarInt;

class RecordHeader extends AbstractStruct
{
    /**
     * @var string
     */
    protected $headerKey = '';

    /**
     * @var string
     */
    protected $value = '';

    public function getFlexibleVersions(): array
    {
        return [];
    }

    public function pack(int $apiVersion = 0): string
    {
        return VarInt::pack(\strlen($this->headerKey)) . $this->headerKey . VarInt::pack(\strlen($this->value)) . $this->value;
    }

    public function unpack(string $data, ?int &$size = null, int $apiVersion = 0): void
    {
        $size = 0;

        $len = VarInt::unpack($data, $tmpSize);
        if ($len > 0) {
            $size += $len;
            $this->headerKey = substr($data, $tmpSize, $len);
            $data            = substr($data, $tmpSize + $len);
        } else {
            $data = substr($data, $tmpSize);
        }
        $size += $tmpSize;

        $len = VarInt::unpack($data, $tmpSize);
        if ($len > 0) {
            $size += $len;
            $this->value = substr($data, $tmpSize, $len);
            $data        = substr($data, $tmpSize + $len);
        } else {
            $data = substr($data, $tmpSize);
        }
        $size += $tmpSize;
    }

    public function getHeaderKey(): string
    {
        return $this->headerKey;
    }

    public function setHeaderKey(string $headerKey): self
    {
        $this->headerKey = $headerKey;

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;

        return $this;
    }
}
