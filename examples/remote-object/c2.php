<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use MongoDB\BSON\UTCDateTime;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

class MongoDBExample
{
    private $collection;

    private $roClient;

    public function __construct()
    {
        $this->roClient   = new Swoole\RemoteObject\Client();
        $client           = $this->roClient->create(MongoDB\Client::class, 'mongodb://localhost:27017');
        $this->collection = $client->myDatabase->users;
    }

    public function insertOne()
    {
        $result = $this->collection->insertOne([
            'name'       => '张三',
            'email'      => 'zhangsan@example.com',
            'age'        => 25,
            'created_at' => $this->roClient->create(UTCDateTime::class),
        ]);

        echo '插入成功，ID: ' . $result->getInsertedId() . "\n";
        return $result->getInsertedId();
    }

    // 插入多条数据
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

        echo '插入了 ' . $result->getInsertedCount() . " 条记录\n";
    }

    // 查询单条数据
    public function findOne()
    {
        $document = $this->collection->findOne(['name' => '张三']);

        if ($document) {
            echo '找到用户: ' . $document['name'] . ', 邮箱: ' . $document['email'] . "\n";
            return $document;
        }
        echo "未找到数据\n";
    }

    // 查询多条数据
    public function findMany()
    {
        // 查询年龄大于 25 的用户
        $cursor = $this->collection->find(
            ['age' => ['$gt' => 25]],
            ['sort' => ['age' => -1]]  // 按年龄降序排序
        );

        echo "查询结果：\n";
        foreach ($cursor as $document) {
            echo "- {$document['name']}, 年龄: {$document['age']}\n";
        }
    }

    // 更新单条数据
    public function updateOne()
    {
        $result = $this->collection->updateOne(
            ['name' => '张三'],
            ['$set' => ['age' => 26, 'city' => '深圳']]
        );

        echo "匹配了 {$result->getMatchedCount()} 条，修改了 {$result->getModifiedCount()} 条\n";
    }

    // 更新多条数据
    public function updateMany()
    {
        $result = $this->collection->updateMany(
            ['age' => ['$gte' => 25]],
            ['$set' => ['status' => 'active']]
        );

        echo "批量更新了 {$result->getModifiedCount()} 条记录\n";
    }

    // 删除单条数据
    public function deleteOne()
    {
        $result = $this->collection->deleteOne(['name' => '张三']);
        echo "删除了 {$result->getDeletedCount()} 条记录\n";
    }

    // 删除多条数据
    public function deleteMany()
    {
        $result = $this->collection->deleteMany(['age' => ['$lt' => 30]]);
        echo "批量删除了 {$result->getDeletedCount()} 条记录\n";
    }

    // 统计数量
    public function count()
    {
        $count = $this->collection->countDocuments(['age' => ['$gte' => 25]]);
        echo "年龄 >= 25 的用户有 {$count} 个\n";
    }
}

Co\run(function () {
    $mongo = new MongoDBExample();
    $mongo->insertOne();
    $mongo->insertMany();

    $mongo->findOne();
    $mongo->findMany();

    $mongo->updateOne();
    $mongo->updateMany();

    $mongo->count();

    // $mongo->deleteOne();
    // $mongo->deleteMany();
});
