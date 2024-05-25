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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StringObject::class)]
class StringObjectTest extends TestCase
{
    public function testReplace(): void
    {
        $str = swoole_string('hello world')->replace('ello', '____');
        $this->assertEquals($str->toString(), 'h____ world');
    }

    public function testLtrim(): void
    {
        $str = swoole_string("   \nhello world\n")->ltrim();
        $this->assertEquals($str->toString(), "hello world\n");
    }

    public function testLength(): void
    {
        $str  = 'hello world';
        $stro = swoole_string($str);
        $this->assertEquals(strlen($str), $stro->length());
    }

    public function testSubstr(): void
    {
        $this->assertEquals(swoole_string('hello swoole and hello world')
            ->substr(4, 8)->toString(), 'o swoole');
    }

    public function testRtrim(): void
    {
        $str = swoole_string("   \nhello world\n")->rtrim();
        $this->assertEquals($str->toString(), "   \nhello world");
    }

    public function testStartsWith(): void
    {
        $this->assertTrue(swoole_string('hello swoole and hello world')->startsWith('hello swoole'));
    }

    public function testContains(): void
    {
        $this->assertTrue(swoole_string('hello swoole and hello world')->contains('swoole'));
    }

    public function chunk(): void
    {
        $r            = swoole_string('hello swoole and hello world')->chunk(5)->toArray();
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

    public function testUpper(): void
    {
        $str    = 'HELLO world';
        $result = swoole_string($str)->upper();
        $this->assertEquals($result->toString(), 'HELLO WORLD');
    }

    public function testPos(): void
    {
        $this->assertEquals(swoole_string('hello swoole and hello world')->pos('and'), 13);
    }

    public function testChunkSplit(): void
    {
        $str = 'hello swoole and hello world';
        $r   = swoole_string($str)
            ->chunkSplit(5, PHP_EOL)->toString()
        ;
        $expectResult = chunk_split($str, 5, PHP_EOL);
        $this->assertEquals($expectResult, $r);
    }

    public function testRepeat(): void
    {
        $this->assertEquals(
            swoole_string('ABC')->repeat(10),
            str_repeat('ABC', 10)
        );
    }

    public function testAppend(): void
    {
        $this->assertEquals(
            swoole_string('ABC')->append(' hello'),
            'ABC hello'
        );

        $this->assertEquals(
            swoole_string('ABC')->append(swoole_string(' hello')),
            'ABC hello'
        );
    }

    public function testChar(): void
    {
        $str = swoole_string('ABC');
        $this->assertEquals($str->char(1), 'B');
        $this->assertEquals($str->char(0), 'A');
        $this->assertEquals($str->char(2), 'C');
        $this->assertEquals($str->char(100), '');
    }

    public function testTrim(): void
    {
        $str = swoole_string("   \nhello world\n")->trim();
        $this->assertEquals($str->toString(), 'hello world');
    }

    public function testIpos(): void
    {
        $this->assertEquals(swoole_string('hello swoole AND hello world')->ipos('and'), 13);
    }

    public function testLower(): void
    {
        $str    = 'HELLO WORLD';
        $result = swoole_string($str)->lower();
        $this->assertEquals($result->toString(), 'hello world');
    }

    public function testSplit(): void
    {
        $str    = 'hello swoole and hello world';
        $result = swoole_string($str)->split(' ');
        $this->assertEquals($result->toArray(), explode(' ', $str));
    }

    public function testLastIndexOf(): void
    {
        $this->assertEquals(swoole_string('hello swoole and hello world')->lastIndexOf('hello'), 17);
    }

    public function testIndexOf(): void
    {
        $this->assertEquals(swoole_string('hello swoole and hello world')->indexOf('swoole'), 6);
    }

    public function testRpos(): void
    {
        $this->assertEquals(swoole_string('hello swoole and hello world')->rpos('hello'), 17);
    }

    public function testReverse(): void
    {
        $this->assertEquals(swoole_string('hello swoole')->reverse()->toString(), strrev('hello swoole'));
    }

    public function testEndsWith(): void
    {
        $this->assertTrue(swoole_string('hello swoole and hello world')->endsWith('world'));
    }

    public function testEquals(): void
    {
        $str = swoole_string('123456');
        $this->assertTrue($str->equals('123456'));
        $this->assertFalse($str->equals('hello world'));
        $this->assertTrue($str->equals(123456));
        $this->assertTrue($str->equals(swoole_string('123456'), true));
        $this->assertFalse($str->equals(123456, true));
    }

    public function testFrom(): void
    {
        $str = StringObject::from('string');
        $this->assertInstanceOf(StringObject::class, $str);
        $this->assertSame('string', (string) $str);
    }
}
