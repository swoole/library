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
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\RemoteObject
 * @covers \Swoole\RemoteObject\Client
 * @covers \Swoole\RemoteObject\Context
 * @covers \Swoole\RemoteObject\Server
 */
class RemoteObjectTest extends TestCase
{
    public function testCallFunction(): void
    {
        self::coRun(function () {
            $client = swoole_get_default_remote_object_client();
            $this->assertEquals('x86_64', $client->call('php_uname', 'm'));
            $gd_info = $client->call('gd_info');
            $this->assertIsArray($gd_info);
            $this->assertGreaterThanOrEqual(10, count($gd_info));
        });
    }

    /**
     * One client is routinely shared, by a service object handling concurrent requests and by the remote objects
     * it created. Its single HTTP connection can only serve one coroutine at a time, so the calls have to queue
     * up instead of ending the process with "Socket has already been bound to another coroutine".
     */
    public function testConcurrentCallsOverOneClient(): void
    {
        self::coRun(function () {
            $client    = swoole_get_default_remote_object_client();
            $waitGroup = new Coroutine\WaitGroup();
            $results   = [];
            for ($i = 0; $i < 8; $i++) {
                $waitGroup->add();
                Coroutine::create(function () use ($client, $i, &$results, $waitGroup) {
                    try {
                        $results[$i] = $client->call('str_repeat', 'x', $i + 1);
                    } finally {
                        $waitGroup->done();
                    }
                });
            }
            $waitGroup->wait();

            ksort($results);
            $this->assertSame(
                array_map(static fn (int $i): string => str_repeat('x', $i + 1), range(0, 7)),
                array_values($results),
                'Every coroutine got the answer to its own call.'
            );
        });
    }

    public function testInvoke()
    {
        self::coRun(function () {
            $client    = swoole_get_default_remote_object_client();
            $o         = $client->create(\Greeter::class, 'Hello swoole');
            $this->assertEquals('Hello swoole, my name is Tianfeng.Han!', $o('my name is Tianfeng.Han'));
        });
    }

    public function testIterator()
    {
        self::coRun(function () {
            $client    = swoole_get_default_remote_object_client();
            $o         = $client->create(\Greeter::class, 'hello swoole');
            $list      =  iterator_to_array($o);
            $this->assertEquals($list, $o->list);
            $this->assertEquals(count($list), count($o));
        });
    }

    public function testResource()
    {
        self::coRun(function () {
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

    /**
     * An exception the server catches is relayed with its message, code and class. The code is not always an
     * integer (a PDOException carries its SQLSTATE), and the client used to hand it straight to the exception
     * constructor, which turned the relay into a TypeError with the message lost.
     */
    public function testServerExceptionWithStringCode(): void
    {
        self::coRun(function () {
            $client = swoole_get_default_remote_object_client();
            $pdo    = $client->create(\PDO::class, 'sqlite::memory:');
            try {
                $pdo->query('SELECT * FROM missing');
                $this->fail('The server-side PDOException is relayed.');
            } catch (RemoteObject\Exception $e) {
                $this->assertStringContainsString('no such table: missing', $e->getMessage());
                $this->assertSame(\PDOException::class, $e->getRemoteClass());
                $this->assertSame('HY000', $e->getRemoteCode());
                $this->assertSame(0, $e->getCode(), 'A code that is not an integer cannot be the exception code.');
            }
        });
    }

    public function testServerExceptionWithIntegerCode(): void
    {
        self::coRun(function () {
            $client = swoole_get_default_remote_object_client();
            try {
                $client->call('json_decode', '{', false, 512, JSON_THROW_ON_ERROR);
                $this->fail('The server-side JsonException is relayed.');
            } catch (RemoteObject\Exception $e) {
                $this->assertStringContainsString('Syntax error', $e->getMessage());
                $this->assertSame(\JsonException::class, $e->getRemoteClass());
                $this->assertSame(JSON_ERROR_SYNTAX, $e->getRemoteCode());
                $this->assertSame(JSON_ERROR_SYNTAX, $e->getCode());
            }
        });
    }

    /**
     * The server's own errors (-1 invalid request, -3 invalid API key) come with a message and no exception; the
     * client used to read the exception anyway, which was a warning and then a TypeError.
     */
    public function testInvalidRequestIsAnException(): void
    {
        self::coRun(function () {
            $client = swoole_get_default_remote_object_client();
            try {
                $client->execute('/no_such_handler', []);
                $this->fail('An unknown request path is reported by the server.');
            } catch (RemoteObject\Exception $e) {
                $this->assertSame('Server Error: invalid request', $e->getMessage());
                $this->assertSame(-1, $e->getCode());
                $this->assertNull($e->getRemoteClass());
                $this->assertNull($e->getRemoteCode());
            }
        });
    }

    /**
     * An object or a resource that call() brings back arrives as a RemoteObject bound to the client that fetched
     * it; unbound, it could neither be used nor release its server-side counterpart. testResource() cannot tell,
     * because a RemoteObject passed back as an argument is resolved server-side by object id alone.
     */
    public function testCallReturnsBoundRemoteObject(): void
    {
        self::coRun(function () {
            $client = swoole_get_default_remote_object_client();
            $bound  = new \ReflectionProperty(RemoteObject::class, 'client');

            $date = $client->call('date_create_immutable', '2026-01-02 03:04:05');
            $this->assertInstanceOf(RemoteObject::class, $date);
            $this->assertSame($client, $bound->getValue($date));
            $this->assertEquals('2026-01-02 03:04:05', $date->format('Y-m-d H:i:s'));

            $fp = $client->call('fopen', 'php://memory', 'w');
            $this->assertInstanceOf(RemoteObject::class, $fp);
            $this->assertSame($client, $bound->getValue($fp));
            $this->assertGreaterThan(0, $fp->getObjectId());
            $client->call('fclose', $fp);
        });
    }

    /**
     * A client lives exactly as long as the remote objects it produced, whether they came from call() or from
     * create(): RemoteObject::$client is the strong reference that keeps it in the weak registry, see
     * Swoole\RemoteObject\Client::$clients.
     */
    public function testClientOutlivesItsRemoteObjects(): void
    {
        self::coRun(function () {
            $registry  = new \ReflectionProperty(RemoteObject\Client::class, 'clients');
            $producers = [
                'call()'   => fn (RemoteObject\Client $client) => $client->call('date_create_immutable', '2026-01-02 03:04:05'),
                'create()' => fn (RemoteObject\Client $client) => $client->create(\DateTimeImmutable::class, '2026-01-02 03:04:05'),
            ];

            foreach ($producers as $producer => $produce) {
                $client = swoole_get_default_remote_object_client();
                $id     = $client->getId();
                $this->assertSame($client, RemoteObject\Client::getInstance($id), $producer);

                $date = $produce($client);
                unset($client);

                // Only the remote object holds the client now; that has to keep it registered, and the object usable.
                $this->assertSame($id, RemoteObject\Client::getInstance($id)?->getId(), $producer);
                $this->assertEquals('2026-01-02 03:04:05', $date->format('Y-m-d H:i:s'), $producer);

                // Look at the registry itself first: getInstance() drops a dead entry, which would hide a client
                // that failed to unregister.
                unset($date);
                $this->assertArrayNotHasKey($id, $registry->getValue(), $producer);
                $this->assertNull(RemoteObject\Client::getInstance($id), $producer);
            }
        });
    }

    /**
     * Destroying a RemoteObject releases the object it stands for on the server. A second handle on the same
     * object id is what makes that observable: it works before the first one is destroyed, and not after.
     */
    public function testDestructReleasesServerSideObject(): void
    {
        self::coRun(function () {
            $client = swoole_get_default_remote_object_client();
            $date   = $client->call('date_create_immutable', '2026-01-02 03:04:05');
            $twin   = unserialize(serialize($date));
            $id     = $date->getObjectId();
            $this->assertSame($id, $twin->getObjectId());
            $this->assertEquals('2026', $twin->format('Y'));

            unset($date);

            try {
                $twin->format('Y');
                $this->fail('The server-side object survived its RemoteObject');
            } catch (RemoteObject\Exception $e) {
                $this->assertStringContainsString("object[#{$id}] not found", $e->getMessage());
            } finally {
                // Disarm the twin: a second /destroy would fail, and RemoteObject::__destruct() would log it.
                (new \ReflectionProperty(RemoteObject::class, 'objectId'))->setValue($twin, 0);
            }
        });
    }

    /**
     * A registry entry belongs to the one client that registered it. Neither an instance built without the
     * constructor nor one forged with a live client's id may take that entry away, and a client cannot be cloned.
     */
    public function testRegistryEntryBelongsToItsClient(): void
    {
        self::coRun(function () {
            $client = swoole_get_default_remote_object_client();
            $id     = $client->getId();
            $class  = RemoteObject\Client::class;

            $ghost = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            unset($ghost);
            $this->assertSame($client, RemoteObject\Client::getInstance($id));

            $property = "\0{$class}\0id";
            $forged   = unserialize(sprintf(
                'O:%d:"%s":1:{s:%d:"%s";s:%d:"%s";}',
                strlen($class),
                $class,
                strlen($property),
                $property,
                strlen($id),
                $id
            ));
            $this->assertSame($id, $forged->getId());
            unset($forged);
            $this->assertSame($client, RemoteObject\Client::getInstance($id));

            try {
                $copy = clone $client;
                $this->fail('A client must not be cloneable');
            } catch (\Error $e) {
                $this->assertStringContainsString('__clone', $e->getMessage());
            }
        });
    }

    public function testMongoDb(): void
    {
        self::coRun(function () {
            $mongo = new class {
                private $collection;

                private readonly RemoteObject\Client $roClient;

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
