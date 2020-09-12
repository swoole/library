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
class ArrayObjectTest extends TestCase
{
    /**
     * @var \Swoole\ArrayObject
     */
    private $data;

    private $data_2;

    private $data_3;

    private $data_4;

    /**
     * @var array
     */
    private $control_data;

    /**
     * ArrayObjectTest constructor.
     * @covers \Swoole\ArrayObject::each()
     * @covers \Swoole\ArrayObject::split()
     * @param string $dataName
     */
    public function __construct(?string $name = null, array $data = [], $dataName = '')
    {
        $_data = '11, 33, 22, 44,12,32,55, 23,19,23';
        $this->data = swoole_string($_data)->split(',')->each(function (&$item) {
            $item = intval($item);
        });

        $this->data_2 = swoole_array(['hello', 'world', 'swoole']);
        $this->data_3 = swoole_array([
            'hello' => 'world',
            'swoole' => 'php',
            'nihao' => '中国人',
        ]);
        $this->data_4 = swoole_array([
            'd' => 'lemon',
            'a' => 'orange',
            'b' => 'banana',
            'c' => 'apple',
        ]);

        $array = explode(',', $_data);
        foreach ($array as &$v) {
            $v = trim($v);
        }
        $this->control_data = $array;
        parent::__construct($name, $data, $dataName);
    }

    /**
     * @covers \Swoole\ArrayObject::toArray()
     */
    public function testToArray()
    {
        $this->assertEquals($this->data->toArray(), $this->control_data);
    }

    /**
     * @covers \Swoole\ArrayObject::each()
     * @covers \Swoole\ArrayObject::sort()
     * @covers \Swoole\ArrayObject::unique()
     */
    public function testMix()
    {
        $datao = clone $this->data;
        $data = $datao->sort()->unique()->toArray();

        $copy_data = $this->control_data;
        sort($copy_data);
        $expectResult = array_unique($copy_data);

        $this->assertEquals($data, $expectResult);
    }

    /**
     * @covers \Swoole\ArrayObject::serialize()
     */
    public function testSerialize()
    {
        $this->assertEquals(serialize($this->data->toArray()), $this->data->serialize());
    }

    /**
     * @covers \Swoole\ArrayObject::unique()
     */
    public function testUnique()
    {
        $data = $this->data->unique()->toArray();
        $copy_data = $this->control_data;
        $expectResult = array_unique($copy_data);

        $this->assertEquals($data, $expectResult);
    }

    public function testTraverse()
    {
        $newArray = [];
        foreach ($this->data as $value) {
            $newArray[] = $value;
        }
        $this->assertEquals($this->control_data, $newArray);
    }

    public function testRemove()
    {
        $data1 = swoole_array_list('hello', 'world', 'swoole');
        $data2 = $data1->toArray();

        $this->assertEquals(
            $data1->remove('hello')->values()->toArray(),
            array_values(array_slice($data2, 1))
        );
    }

    public function testFilter()
    {
        $data = $this->data->filter(function ($v) {
            return $v > 20;
        });

        $find = false;
        foreach ($data as $v) {
            if ($v <= 20) {
                $find = true;
                break;
            }
        }
        $this->assertFalse($find);
    }

    public function testOffsetExists()
    {
        $this->assertEquals($this->data->offsetExists(9), isset($this->control_data[9]));
    }

    public function testSearch()
    {
        $this->assertEquals($this->data_2->search('swoole'), 2);
    }

    public function testValues()
    {
        $this->assertEquals(
            $this->data_3->values()->toArray(),
            array_values($this->data_3->toArray())
        );
    }

    public function testMap()
    {
        $arr1 = [1, 2, 3, 4, 5];
        $arr2 = [6, 7, 8, 9, 10];
        $arr3 = [62, 71, 82, 93, 103];

        $this->assertEquals(swoole_array($arr1)->map(function ($val1) {
            return $val1 * 3;
        })->toArray(), [
            3,
            6,
            9,
            12,
            15,
        ]);

        $this->assertEquals(swoole_array($arr1)->map(function ($val1, $val2, $val3) {
            return $val1 + $val2 + $val3;
        }, $arr2, $arr3)->toArray(), [
            69,
            80,
            93,
            106,
            118,
        ]);
    }

    public function testClear()
    {
        $this->assertEquals((clone $this->data->clear())->toArray(), []);
    }

    public function testReverse()
    {
        $this->assertEquals($this->data->reverse()->toArray(), array_reverse($this->control_data));
    }

    public function testDelete()
    {
        $data = clone $this->data_3;
        $expectData = $data->toArray();
        unset($expectData['swoole']);

        $this->assertEquals(
            $data->delete('swoole')->toArray(),
            $expectData
        );
    }

    public function testContains()
    {
        $this->assertTrue($this->data_2->contains('swoole'));
        $this->assertFalse($this->data_2->contains('aliyun'));
    }

    public function testUnserialize()
    {
        $str = serialize($this->data->toArray());
        $this->assertEquals(
            swoole_array([])->unserialize($str)->toArray(),
            $this->data->toArray()
        );
    }

    public function testPushBack()
    {
        $data = clone $this->data;
        $data->pushBack(999);
        $this->assertEquals($data->search(999), $data->count() - 1);
    }

    public function testPushFront()
    {
        $data = clone $this->data;
        $data->pushFront(999);
        $this->assertEquals($data->search(999), 0);
    }

    public function testPopFront()
    {
        $data = clone $this->data;
        $value = $data->popFront();
        $this->assertEquals($value, $this->data->first());
        $this->assertEquals($data->count(), $this->data->count() - 1);
    }

    public function testPopBack()
    {
        $data = clone $this->data;
        $value = $data->popBack();
        $this->assertEquals($value, $this->data->last());
        $this->assertEquals($data->count(), $this->data->count() - 1);
    }

    public function testPop()
    {
        $data = clone $this->data;
        $value = $data->pop();
        $this->assertEquals($value, $this->data->last());
        $this->assertEquals($data->count(), $this->data->count() - 1);
    }

    public function testPush()
    {
        $data = clone $this->data;
        $data->pushBack(999);
        $this->assertEquals($data->search(999), $data->count() - 1);
    }

    public function testAppend()
    {
        $data = clone $this->data;
        $data->append(999)->append(888);
        $data->append(10000, 20000, 30000);
        $this->assertTrue($data->contains(999));
        $this->assertTrue($data->contains(888));
        $this->assertTrue($data->contains(30000));
    }

    public function testShuffle()
    {
        $data1 = clone $this->data;
        $data2 = clone $this->data;
        $data1->shuffle();
        $this->assertNotEquals($data1->values()->toArray(), $data2->values()->toArray());
        $this->assertEquals($data1->values()->sort()->toArray(), $data2->values()->sort()->toArray());
    }

    public function testColumn()
    {
        $records = [
            [
                'id' => 2135,
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
            [
                'id' => 3245,
                'first_name' => 'Sally',
                'last_name' => 'Smith',
            ],
            [
                'id' => 5342,
                'first_name' => 'Jane',
                'last_name' => 'Jones',
            ],
            [
                'id' => 5623,
                'first_name' => 'Peter',
                'last_name' => 'Doe',
            ],
        ];

        $this->assertEquals(
            array_column($records, 'first_name'),
            swoole_array($records)->column('first_name')->toArray()
        );

        $this->assertEquals(
            array_column($records, 'first_name', 'id'),
            swoole_array($records)->column('first_name', 'id')->toArray()
        );
    }

    public function testIndexOf()
    {
        $this->assertEquals($this->data->indexOf(23), 7);
    }

    public function testProduct()
    {
        $value = 1;
        foreach ($this->control_data as $v) {
            $value *= $v;
        }
        $this->assertEquals($this->data->product(), $value);
    }

    public function testAsort()
    {
        $data1 = $this->data_4->toArray();
        $data2 = clone $this->data_4;
        asort($data1);
        $this->assertEquals($data1, $data2->asort()->toArray());
    }

    public function testArsort()
    {
        $data1 = $this->data_4->toArray();
        $data2 = clone $this->data_4;
        arsort($data1);
        $this->assertEquals($data1, $data2->arsort()->toArray());
    }

    public function testKsort()
    {
        $data1 = $this->data_4->toArray();
        $data2 = clone $this->data_4;
        ksort($data1);
        $this->assertEquals($data1, $data2->ksort()->toArray());
    }

    public function testKrsort()
    {
        $data1 = $this->data_4->toArray();
        $data2 = clone $this->data_4;
        krsort($data1);
        $this->assertEquals($data1, $data2->krsort()->toArray());
    }

    public function testSort()
    {
        $data1 = $this->data->toArray();
        $data2 = clone $this->data;
        sort($data1);
        $this->assertEquals($data1, $data2->sort()->toArray());
    }

    public function testRsort()
    {
        $data1 = $this->data->toArray();
        $data2 = clone $this->data;
        rsort($data1);
        $this->assertEquals($data1, $data2->rsort()->toArray());
    }

    public function testUasort()
    {
        $data1 = ['a' => 4, 'b' => 8, 'c' => -1, 'd' => -9, 'e' => 2, 'f' => 5, 'g' => 3, 'h' => -4];
        $data2 = swoole_array($data1);
        $cmp = function ($a, $b) {
            if ($a == $b) {
                return 0;
            }
            return ($a < $b) ? -1 : 1;
        };
        uasort($data1, $cmp);
        $this->assertEquals($data1, $data2->uasort($cmp)->toArray());
    }

    public function testNatsort()
    {
        $data1 = ['img12.png', 'img10.png', 'img2.png', 'img1.png'];
        $data2 = swoole_array($data1);
        natsort($data1);
        $this->assertEquals($data1, $data2->natsort()->toArray());
    }

    public function testNatcasesort()
    {
        $data1 = ['IMG0.png', 'img12.png', 'img10.png', 'img2.png', 'img1.png', 'IMG3.png'];
        $data2 = swoole_array($data1);
        natcasesort($data1);
        $this->assertEquals($data1, $data2->natcasesort()->toArray());
    }

    public function testUsort()
    {
        $cmp = function ($a, $b) {
            if ($a == $b) {
                return 0;
            }
            return ($a < $b) ? -1 : 1;
        };

        $data1 = [3, 2, 5, 6, 1];
        $data2 = swoole_array($data1);
        usort($data1, $cmp);
        $this->assertEquals($data1, $data2->usort($cmp)->toArray());
    }

    public function testUksort()
    {
        $cmp = function ($a, $b) {
            $a = preg_replace('@^(a|an|the) @', '', $a);
            $b = preg_replace('@^(a|an|the) @', '', $b);
            return strcasecmp($a, $b);
        };

        $data1 = ['John' => 1, 'the Earth' => 2, 'an apple' => 3, 'a banana' => 4];
        $data2 = swoole_array($data1);
        uksort($data1, $cmp);
        $this->assertEquals($data1, $data2->uksort($cmp)->toArray());
    }

    public function testCount()
    {
        $this->assertEquals($this->data->count(), count($this->control_data));
    }

    public function testOffsetGet()
    {
        $this->assertEquals($this->control_data[6], $this->data[6]);
    }

    public function testReduce()
    {
        $this->assertEquals($this->data->product(), $this->data->reduce(function ($carry, $item) {
            $carry *= $item;
            return $carry;
        }, 1));
    }

    public function testOffsetSet()
    {
        $value = 9999;
        $data = clone $this->data;
        $data[7] = $value;
        $this->assertEquals($data->get(7), $value);
    }

    public function testOffsetUnset()
    {
        $data = clone $this->data;
        unset($data[6]);
        $this->assertFalse($data->exists(6));
    }

    public function testIsEmpty()
    {
        $this->assertFalse($this->data->isEmpty());
        $this->assertTrue(swoole_array()->isEmpty());
    }

    public function testKeys()
    {
        $this->assertEquals(
            $this->data_4->keys()->toArray(),
            array_keys($this->data_4->toArray())
        );
    }

    public function testSet()
    {
        $data = clone $this->data_3;
        $data->set('com', 'tal100');
        $this->assertEquals($data->get('com'), 'tal100');
        $this->assertEquals($data->count(), $this->data_3->count() + 1);
    }

    public function testInsert()
    {
        $data = clone $this->data;
        $data->insert(7, 888);
        $this->assertEquals($data->get(7), 888);
    }

    public function testRandomGet()
    {
        $this->assertTrue($this->data->contains($this->data->randomGet()));
    }

    public function testSum()
    {
        $this->assertEquals($this->data->sum(), array_sum($this->control_data));
    }

    public function testFlip()
    {
        $result = $this->data_3->flip();
        $this->assertEquals($result->toArray(), array_flip($this->data_3->toArray()));
    }

    public function testJoin()
    {
        $this->assertEquals($this->data_2->join('-'), implode('-', $this->data_2->toArray()));
    }

    public function testChunk()
    {
        $this->assertEquals(
            $this->data->chunk(2)->toArray(),
            array_chunk($this->data->toArray(), 2)
        );
    }

    public function testSlice()
    {
        $this->assertEquals(
            $this->data->slice(2, 4)->toArray(),
            array_slice($this->control_data, 2, 4)
        );
    }

    public function testLastIndexOf()
    {
        $this->assertEquals($this->data->lastIndexOf(23), 9);
    }

    public function testExists()
    {
        $this->assertTrue($this->data_3->exists('swoole'));
    }

    public function testFirst()
    {
        $this->assertEquals($this->data_4->first(), 'lemon');
    }

    public function testLast()
    {
        $this->assertEquals($this->data_4->last(), 'apple');
    }

    public function testFirstKey()
    {
        $this->assertEquals($this->data_4->firstKey(), 'd');
    }

    public function testLastKey()
    {
        $this->assertEquals($this->data_4->lastKey(), 'c');
    }
}
