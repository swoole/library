<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Consumer\Struct;

class ConsumerGenerationPair
{
    /**
     * @var string
     */
    private $consumer;

    /**
     * @var int
     */
    private $generation;

    public function __construct(string $consumer, int $generation)
    {
        $this->consumer   = $consumer;
        $this->generation = $generation;
    }

    public function getConsumer(): string
    {
        return $this->consumer;
    }

    public function getGeneration(): int
    {
        return $this->generation;
    }
}
