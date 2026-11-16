<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole;

use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\MultibyteStringObject
 */
class MultibyteStringObjectTest extends TestCase
{
    public function testLength(): void
    {
        $str    = 'hello world';
        $length = swoole_mbstring($str)->length();
        $this->assertEquals(strlen($str), $length);
    }

    public function testIndexOf(): void
    {
        $this->assertEquals(swoole_mbstring('hello swoole and hello world')->indexOf('swoole'), 6);
    }

    public function testLastIndexOf(): void
    {
        $this->assertEquals(swoole_mbstring('hello swoole and hello world')->lastIndexOf('hello'), 17);
    }

    public function testPos(): void
    {
        $this->assertEquals(swoole_mbstring('hello swoole and hello world')->pos('and'), 13);
    }

    public function testRPos(): void
    {
        $this->assertEquals(swoole_mbstring('hello swoole and hello world')->rpos('hello'), 17);
    }

    public function testIPos(): void
    {
        $this->assertEquals(swoole_mbstring('hello swoole AND hello world')->ipos('and'), 13);
    }

    public function testSubstr(): void
    {
        $this->assertEquals(swoole_mbstring('hello swoole and hello world')
            ->substr(4, 8)->toString(), 'o swoole');
    }

    public function testMultibyteOperations(): void
    {
        $str = swoole_mbstring('hé世界🙂');
        $this->assertSame(5, $str->length());
        $this->assertSame('é', $str->char(1));
        $this->assertSame('🙂', $str->char(-1));
        $this->assertSame('h', $str->char(-5));
        $this->assertSame('', $str->char(-6));
        $this->assertSame('', $str->char(5));
        $this->assertSame('', swoole_mbstring()->char(0));
        $this->assertSame('🙂界世éh', $str->reverse()->toString());
        $this->assertSame('äbc', swoole_mbstring('ÄBC')->lower()->toString());
        $this->assertSame('ÄBC', swoole_mbstring('äbc')->upper()->toString());
        $this->assertSame(['hé', '世界', '🙂'], $str->chunk(2)->toArray());
        $this->assertSame('hé|世界|🙂|', $str->chunkSplit(2, '|')->toString());
        $this->assertSame('|', swoole_mbstring()->chunkSplit(2, '|')->toString());
        $this->assertSame(['h', '世界🙂'], $str->split('é')->toArray());
        $this->assertSame('é世', $str->substr(1, 2)->toString());
        $this->assertSame(2, $str->pos('世'));
        $this->assertSame(3, $str->rpos('界'));
        $this->assertSame(1, swoole_mbstring('hÉllo')->ipos('é'));
        $this->assertSame('hé世界🙂', $str->toString());
        $this->assertInstanceOf(MultibyteStringObject::class, $str->append('!')->trim()->substr(0));
    }

    public function chunk(): void
    {
        $r            = swoole_mbstring('hello swoole and hello world')->chunk(5)->toArray();
        $expectResult = [
            0 => 'hello',
            1 => ' swoo',
            2 => 'le an',
            3 => 'd hel',
            4 => 'lo wo',
            5 => 'rld',
        ];
        $this->assertEquals($expectResult, $r);
    }
}
