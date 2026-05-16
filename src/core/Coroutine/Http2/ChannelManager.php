<?php

declare(strict_types=1);

namespace Swoole\Coroutine\Http2;

use Swoole\Coroutine\Channel;

class ChannelManager
{
    /**
     * @var Channel[]
     */
    protected array $channels = [];

    public function get(int $streamId, bool $initialize = false): ?Channel
    {
        if (isset($this->channels[$streamId])) {
            return $this->channels[$streamId];
        }

        if ($initialize) {
            return $this->channels[$streamId] = $this->make(1);
        }

        return null;
    }

    public function make(int $limit): Channel
    {
        return new Channel($limit);
    }

    public function close(int $streamId): void
    {
        if ($channel = $this->channels[$streamId] ?? null) {
            $channel->close();
        }

        unset($this->channels[$streamId]);
    }

    public function getChannels(): array
    {
        return $this->channels;
    }

    public function flush(): void
    {
        $channels = $this->getChannels();
        $streamIds = array_keys($channels);
        foreach ($streamIds as $streamId) {
            $this->close($streamId);
        }
    }

    public function isEmpty(): bool
    {
        return count($this->channels) === 0;
    }
}
