<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Psr\Cache\Table;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Swoole\Psr\Cache\SerializerInterface;
use Swoole\Table;

class CacheItemPool implements CacheItemPoolInterface
{
    /**
     * @var int
     */
    private $size;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var array
     */
    private $deferred;

    /**
     * @var Table
     */
    private $table;

    public function __construct(int $size, SerializerInterface $serializer)
    {
        $this->size = $size;
        $this->serializer = $serializer;

        $this->deferred = [];
        $this->table = $this->createTable();
    }

    /**
     * @param string $key
     */
    public function getItem($key): CacheItemInterface
    {
        $row = $this->table->get($key);
        return $this->cacheItemFromRow($row);
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $this->getItem($key);
        }
    }

    /**
     * @param string $key
     */
    public function hasItem($key): bool
    {
        return $this->table->exists($key);
    }

    public function clear(): bool
    {
        $destroyed = $this->table->destroy();
        $this->table = $this->createTable();
        return $destroyed;
    }

    /**
     * @param string $key
     */
    public function deleteItem($key): bool
    {
        return $this->table->delete($key);
    }

    /**
     * @param array<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            if (!$this->deleteItem($key)) {
                return false;
            }
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->table->set($item->getKey(), $this->rowFromCacheItem($item));
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->deferred[] = $item;
        return true;
    }

    public function commit(): bool
    {
        foreach ($this->deferred as $item) {
            if (!$this->save($item)) {
                return false;
            }
        }

        $this->deferred = [];
        return true;
    }

    private function createTable(): Table
    {
        $table = new Table($this->size);
        $table->column('key', Table::TYPE_STRING, 64);
        $table->column('value', Table::TYPE_STRING, 64);
        $table->column('ttl', Table::TYPE_INT);
        $table->create();

        return $table;
    }

    private function cacheItemFromRow(array $row): CacheItemInterface
    {
        return new CacheItem($row['key'], $this->serializer->unserialize($row['value']));
    }

    private function rowFromCacheItem(CacheItemInterface $item): array
    {
        return [
            'key' => $item->getKey(),
            'value' => $this->serializer->serialize($item->get()),
        ];
    }
}
