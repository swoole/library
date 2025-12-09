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

use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function Swoole\Coroutine\run;

/**
 * @internal
 */
#[CoversClass(RemoteObject::class)]
#[CoversClass(RemoteObject\Context::class)]
#[CoversClass(RemoteObject\Server::class)]
#[CoversClass(RemoteObject\Client::class)]
class RemoteObjectTest extends TestCase
{
    public function testCallFunction(): void
    {
        run(function () {
            $client = swoole_get_default_remote_object_client();
            $this->assertEquals('x86_64', $client->call('php_uname', 'm'));
            $gd_info = $client->call('gd_info');
            $this->assertIsArray($gd_info);
            $this->assertGreaterThanOrEqual(10, count($gd_info));
        });
    }

    public function testInvoke()
    {
        run(function () {
            $client    = swoole_get_default_remote_object_client();
            $o         = $client->create(\Greeter::class, 'Hello swoole');
            $this->assertEquals('Hello swoole, my name is Tianfeng.Han!', $o('my name is Tianfeng.Han'));
        });
    }

    public function testIterator()
    {
        run(function () {
            $client    = swoole_get_default_remote_object_client();
            $o         = $client->create(\Greeter::class, 'hello swoole');
            $list      =  iterator_to_array($o);
            $this->assertEquals($list, $o->list);
            $this->assertEquals(count($list), count($o));
        });
    }

    public function testResource()
    {
        run(function () {
            $client = swoole_get_default_remote_object_client();
            $fp     = $client->call('fopen', '/tmp/data.txt', 'w');

            $n     = random_int(1024, 65536);
            $wdata = random_bytes($n);
            $client->call('fwrite', $fp, $wdata);
            $client->call('fclose', $fp);

            $fp    = $client->call('fopen', '/tmp/data.txt', 'r');
            $rdata = $client->call('fread', $fp, $n);
            $client->call('fclose', $fp);
            $this->assertEquals($wdata, $rdata);
        });
    }

    public function testMongoDb(): void
    {
        run(function () {
            $mongo = new class {
                private $collection;

                private RemoteObject\Client $roClient;

                public function __construct()
                {
                    $this->roClient   =     swoole_get_default_remote_object_client();
                    $client           = $this->roClient->create(\MongoDB\Client::class, MONGODB_SERVER_URL);
                    $this->collection = $client->myDatabase->users;
                }

                public function insertOne(): string
                {
                    $result = $this->collection->insertOne([
                        'name'       => '张三',
                        'email'      => 'zhangsan@example.com',
                        'age'        => 25,
                        'created_at' => $this->roClient->create(UTCDateTime::class),
                    ]);

                    return strval($result->getInsertedId());
                }

                public function insertMany()
                {
                    $result = $this->collection->insertMany([
                        [
                            'name'  => '李四',
                            'email' => 'lisi@example.com',
                            'age'   => 30,
                            'city'  => '北京',
                        ],
                        [
                            'name'  => '王五',
                            'email' => 'wangwu@example.com',
                            'age'   => 28,
                            'city'  => '上海',
                        ],
                    ]);

                    return $result->getInsertedCount();
                }

                public function findOne()
                {
                    $document = $this->collection->findOne(['name' => '张三']);
                    if ($document) {
                        return $document;
                    }
                    return null;
                }

                public function findMany()
                {
                    $cursor = $this->collection->find(
                        ['age' => ['$gt' => 25]],
                        ['sort' => ['age' => -1]]  // 按年龄降序排序
                    );
                    return $cursor->toArray();
                }

                public function updateOne()
                {
                    $result = $this->collection->updateOne(
                        ['name' => '张三'],
                        ['$set' => ['age' => 26, 'city' => '深圳']]
                    );

                    return $result->getModifiedCount();
                }

                public function updateMany()
                {
                    $result = $this->collection->updateMany(
                        ['age' => ['$gte' => 25]],
                        ['$set' => ['status' => 'active']]
                    );

                    return $result->getModifiedCount();
                }

                public function deleteOne()
                {
                    $result = $this->collection->deleteOne(['name' => '张三']);
                    return $result->getDeletedCount();
                }

                public function deleteMany()
                {
                    $result = $this->collection->deleteMany(['age' => ['$lt' => 30]]);
                    return $result->getDeletedCount();
                }

                public function clean(): void
                {
                    $this->collection->deleteMany([]);
                }

                public function count()
                {
                    return $this->collection->countDocuments(['age' => ['$gte' => 25]]);
                }
            };

            $mongo->clean();

            $this->assertNotEmpty($mongo->insertOne());
            $this->assertEquals(2, $mongo->insertMany());

            $doc = $mongo->findOne();
            $this->assertNotEmpty($doc);
            $this->assertEquals('张三', $doc['name']);
            $this->assertEquals('zhangsan@example.com', $doc['email']);

            $docs = $mongo->findMany();
            $this->assertNotEmpty($docs);
            $this->assertCount(2, $docs);
            $this->assertEquals('李四', $docs[0]['name']);
            $this->assertEquals('王五', $docs[1]['name']);

            $this->assertEquals(1, $mongo->updateOne());
            $this->assertEquals(3, $mongo->updateMany());

            $this->assertEquals(3, $mongo->count());
            $this->assertEquals(1, $mongo->deleteOne());
            $this->assertEquals(1, $mongo->deleteMany());
        });
    }
}
