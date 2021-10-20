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

use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MultibyteStringObjectTest extends TestCase
{
    /**
     * @covers \Swoole\MultibyteStringObject::length()
     */
    public function testLength()
    {
        $str = 'hello world';
        $length = swoole_mbstring($str)->length();
        $this->assertEquals(strlen($str), $length);
    }

    /**
     * @covers \Swoole\MultibyteStringObject::indexOf()
     */
    public function testIndexOf()
    {
        $this->assertEquals(swoole_mbstring('hello swoole and hello world')->indexOf('swoole'), 6);
    }

    /**
     * @covers \Swoole\MultibyteStringObject::lastIndexOf()
     */
    public function testLastIndexOf()
    {
        $this->assertEquals(swoole_mbstring('hello swoole and hello world')->lastIndexOf('hello'), 17);
    }

    /**
     * @covers \Swoole\MultibyteStringObject::pos()
     */
    public function testPos()
    {
        $this->assertEquals(swoole_mbstring('hello swoole and hello world')->pos('and'), 13);
    }

    /**
     * @covers \Swoole\MultibyteStringObject::rpos()
     */
    public function testRPos()
    {
        $this->assertEquals(swoole_mbstring('hello swoole and hello world')->rpos('hello'), 17);
    }

    /**
     * @covers \Swoole\MultibyteStringObject::ipos()
     */
    public function testIPos()
    {
        $this->assertEquals(swoole_mbstring('hello swoole AND hello world')->ipos('and'), 13);
    }

    /**
     * @covers \Swoole\MultibyteStringObject::substr()
     */
    public function testSubstr()
    {
        $this->assertEquals(swoole_mbstring('hello swoole and hello world')
            ->substr(4, 8)->toString(), 'o swoole');
    }

    /**
     * @covers \Swoole\MultibyteStringObject::chunk()
     */
    public function chunk()
    {
        $r = swoole_mbstring('hello swoole and hello world')->chunk(5)->toArray();
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
