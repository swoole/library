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

use PHPUnit\Framework\TestCase;
use Swoole\Psr\Cache\PhpSerializer;

/**
 * @internal
 * @coversNothing
 */
class CacheItemPoolTest extends TestCase
{
    /**
     * @var CacheItemPool
     */
    private $pool;

    protected function setUp(): void
    {
        $this->pool = new CacheItemPool(1024, new PhpSerializer());
    }

    public function testClear()
    {
        $this->pool->save(new CacheItem('foo', 'bar'));
        $this->assertTrue($this->pool->hasItem('foo'));
        $this->pool->clear();
        $this->assertFalse($this->pool->hasItem('foo'));
    }

    public function testDeleteItem()
    {
        $this->pool->save(new CacheItem('foo', 'bar'));
        $this->assertTrue($this->pool->hasItem('foo'));
        $this->pool->deleteItem('foo');
        $this->assertFalse($this->pool->hasItem('foo'));
    }

    public function testSaveDeferredAndCommit()
    {
        $this->pool->saveDeferred(new CacheItem('foo', 'bar'));
        $this->assertFalse($this->pool->hasItem('foo'));
        $this->pool->commit();
        $this->assertTrue($this->pool->hasItem('foo'));
    }

    public function testDeleteItems()
    {
        $this->pool->save(new CacheItem('foo', 'bar'));
        $this->assertTrue($this->pool->hasItem('foo'));
        $this->pool->deleteItems(['foo']);
        $this->assertFalse($this->pool->hasItem('foo'));
    }

    public function testGetItems()
    {
        $this->pool->save(new CacheItem('foo', 'bar'));
        $item = $this->pool->getItem('foo');
        $this->assertSame('foo', $item->getKey());
        $this->assertSame('bar', $item->get());
    }

    public function testGetItem()
    {
        $this->pool->save(new CacheItem('foo', 'bar'));

        foreach ($this->pool->getItems(['foo']) as $item) {
            $this->assertSame('foo', $item->getKey());
            $this->assertSame('bar', $item->get());
        }
    }
}
