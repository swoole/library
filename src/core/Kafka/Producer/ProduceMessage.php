<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Producer;

use Swoole\Kafka\Protocol\RecordBatch\RecordHeader;

class ProduceMessage
{
    /**
     * @var string
     */
    protected $topic;

    /**
     * @var string|null
     */
    protected $value;

    /**
     * @var string|null
     */
    protected $key;

    /**
     * @var array<int|string, RecordHeader|string>
     */
    protected $headers;

    /**
     * @var int|null
     */
    protected $partitionIndex;

    /**
     * @param array<int|string, RecordHeader|string> $headers
     */
    public function __construct(string $topic, ?string $value, ?string $key = null, array $headers = [], ?int $partitionIndex = null)
    {
        $this->topic          = $topic;
        $this->value          = $value;
        $this->key            = $key;
        $this->headers        = $headers;
        $this->partitionIndex = $partitionIndex;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    /**
     * @return array<int|string, RecordHeader|string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getPartitionIndex(): ?int
    {
        return $this->partitionIndex;
    }
}
