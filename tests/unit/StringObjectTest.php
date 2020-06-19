<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests;

use PHPUnit\Framework\TestCase;
use Swoole\StringObject;

class StringObjectTest extends TestCase
{
    /**
     * @covers \Swoole\StringObject::replace()
     */
    public function testReplace()
    {
        $str = swoole_string('hello world')->replace('ello', '____');
        $this->assertEquals($str->toString(), 'h____ world');
    }

    /**
     * @covers \Swoole\StringObject::ltrim()
     */
    public function testLtrim()
    {
        $str = swoole_string("   \nhello world\n")->ltrim();
        $this->assertEquals($str->toString(), "hello world\n");
    }

    /**
     * @covers \Swoole\StringObject::length()
     */
    public function testLength()
    {
        $str = "hello world";
        $stro = swoole_string($str);
        $this->assertEquals(strlen($str), $stro->length());
    }

    /**
     * @covers \Swoole\StringObject::substr()
     */
    public function testSubstr()
    {
        $this->assertEquals(swoole_string("hello swoole and hello world")
            ->substr(4, 8)->toString(), 'o swoole');
    }

    /**
     * @covers \Swoole\StringObject::rtrim()
     */
    public function testRtrim()
    {
        $str = swoole_string("   \nhello world\n")->rtrim();
        $this->assertEquals($str->toString(), "   \nhello world");
    }

    /**
     * @covers \Swoole\StringObject::startsWith()
     */
    public function testStartsWith()
    {
        $this->assertTrue(swoole_string("hello swoole and hello world")->startsWith('hello swoole'));
    }

    /**
     * @covers \Swoole\StringObject::contains()
     */
    public function testContains()
    {
        $this->assertTrue(swoole_string("hello swoole and hello world")->contains('swoole'));
    }

    /**
     * @covers \Swoole\StringObject::contains()
     */
    public function chunk()
    {
        $r = swoole_string("hello swoole and hello world")->chunk(5)->toArray();
        $expectResult = array(
            0 => 'hello',
            1 => ' swoo',
            2 => 'le an',
            3 => 'd hel',
            4 => 'lo wo',
            5 => 'rld',
        );
        $this->assertEquals($expectResult, $r);
    }

    /**
     * @covers \Swoole\StringObject::upper()
     */
    public function testUpper()
    {
        $str = "HELLO world";
        $result = swoole_string($str)->upper();
        $this->assertEquals($result->toString(), 'HELLO WORLD');
    }

    /**
     * @covers \Swoole\StringObject::pos()
     */
    public function testPos()
    {
        $this->assertEquals(swoole_string("hello swoole and hello world")->pos('and'), 13);
    }

    /**
     * @covers \Swoole\StringObject::chunkSplit()
     */
    public function testChunkSplit()
    {
        $str = "hello swoole and hello world";
        $r = swoole_string($str)
            ->chunkSplit(5, PHP_EOL)->toString();
        $expectResult = chunk_split($str, 5, PHP_EOL);
        $this->assertEquals($expectResult, $r);
    }

    /**
     * @covers \Swoole\StringObject::repeat()
     */
    public function testRepeat()
    {
        $this->assertEquals(swoole_string('ABC')->repeat(10),
            str_repeat('ABC', 10)
        );
    }

    /**
     * @covers \Swoole\StringObject::append()
     */
    public function testAppend()
    {
        $this->assertEquals(swoole_string('ABC')->append(" hello"),
            'ABC hello'
        );

        $this->assertEquals(swoole_string('ABC')->append(swoole_string(" hello")),
            'ABC hello'
        );
    }

    /**
     * @covers \Swoole\StringObject::char()
     */
    public function testChar()
    {
        $str = swoole_string('ABC');
        $this->assertEquals($str->char(1), 'B');
        $this->assertEquals($str->char(0), 'A');
        $this->assertEquals($str->char(2), 'C');
        $this->assertEquals($str->char(100), '');
    }

    /**
     * @covers \Swoole\StringObject::trim()
     */
    public function testTrim()
    {
        $str = swoole_string("   \nhello world\n")->trim();
        $this->assertEquals($str->toString(), "hello world");
    }

    /**
     * @covers \Swoole\StringObject::ipos()
     */
    public function testIpos()
    {
        $this->assertEquals(swoole_string("hello swoole AND hello world")->ipos('and'), 13);
    }

    /**
     * @covers \Swoole\StringObject::lower()
     */
    public function testLower()
    {
        $str = "HELLO WORLD";
        $result = swoole_string($str)->lower();
        $this->assertEquals($result->toString(), 'hello world');
    }

    /**
     * @covers \Swoole\StringObject::split()
     */
    public function testSplit()
    {
        $str = "hello swoole and hello world";
        $result = swoole_string($str)->split(' ');
        $this->assertEquals($result->toArray(), explode(' ', $str));
    }

    /**
     * @covers \Swoole\StringObject::lastIndexOf()
     */
    public function testLastIndexOf()
    {
        $this->assertEquals(swoole_string("hello swoole and hello world")->lastIndexOf('hello'), 17);
    }

    /**
     * @covers \Swoole\StringObject::indexOf()
     */
    public function testIndexOf()
    {
        $this->assertEquals(swoole_string("hello swoole and hello world")->indexOf('swoole'), 6);
    }

    /**
     * @covers \Swoole\StringObject::rpos()
     */
    public function testRpos()
    {
        $this->assertEquals(swoole_string("hello swoole and hello world")->rpos('hello'), 17);
    }

    /**
     * @covers \Swoole\StringObject::endsWith()
     */
    public function testEndsWith()
    {
        $this->assertTrue(swoole_string("hello swoole and hello world")->endsWith('world'));
    }
}
