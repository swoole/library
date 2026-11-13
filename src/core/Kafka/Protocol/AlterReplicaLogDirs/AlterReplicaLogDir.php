<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\AlterReplicaLogDirs;

use Swoole\Kafka\Protocol\AbstractStruct;
use Swoole\Kafka\Protocol\ProtocolField;

class AlterReplicaLogDir extends AbstractStruct
{
    /**
     * The absolute directory path.
     *
     * @var string
     */
    protected $path = '';

    /**
     * The topics to add to the directory.
     *
     * @var AlterReplicaLogDirTopic[]
     */
    protected $topics = [];

    public function __construct()
    {
        if (!isset(self::$maps[self::class])) {
            self::$maps[self::class] = [
                new ProtocolField('path', 'string', false, [0, 1], [], [], [], null),
                new ProtocolField('topics', AlterReplicaLogDirTopic::class, true, [0, 1], [], [], [], null),
            ];
            self::$taggedFieldses[self::class] = [];
        }
    }

    public function getFlexibleVersions(): array
    {
        return [];
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    /**
     * @return AlterReplicaLogDirTopic[]
     */
    public function getTopics(): array
    {
        return $this->topics;
    }

    /**
     * @param AlterReplicaLogDirTopic[] $topics
     */
    public function setTopics(array $topics): self
    {
        $this->topics = $topics;

        return $this;
    }
}
