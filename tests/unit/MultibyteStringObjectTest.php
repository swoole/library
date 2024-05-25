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
#[CoversClass(MultibyteStringObject::class)]
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
