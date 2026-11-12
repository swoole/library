<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Grpc\Etcd;

use Etcdserverpb\Compare;
use Etcdserverpb\KVClient;
use Etcdserverpb\TxnRequest;
use Swoole\Grpc\Example\Etcd\Client as EtcdClient;
use Swoole\Grpc\Example\Etcd\Txn;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Example\Etcd\Client
 * @covers \Swoole\Grpc\Example\Etcd\Txn
 */
class TxnTest extends TestCase
{
    public function testPrefixEnd(): void
    {
        $this->assertSame('fop', EtcdClient::prefixEnd('foo'));
        $this->assertSame('a', EtcdClient::prefixEnd('`'));
        $this->assertSame("\0", EtcdClient::prefixEnd("\xff\xff"));
        $this->assertSame("\0", EtcdClient::prefixEnd(''));
    }

    public function testPutOpBuilder(): void
    {
        $op  = Txn::put('key', 'value', 7);
        $put = $op->getRequestPut();
        $this->assertSame('key', $put->getKey());
        $this->assertSame('value', $put->getValue());
        $this->assertSame(7, $put->getLease());
    }

    public function testGetAndDeleteOpBuilders(): void
    {
        $this->assertSame('k', Txn::get('k')->getRequestRange()->getKey());
        $this->assertSame('k', Txn::delete('k')->getRequestDeleteRange()->getKey());
    }

    public function testCompareTargets(): void
    {
        $kv  = (new \ReflectionClass(KVClient::class))->newInstanceWithoutConstructor();
        $txn = new Txn($kv);

        // A fluent chain; commit() is not called (no connection). We capture the built request
        // by reflection-independent means: verify the fluent API returns itself.
        $this->assertSame($txn, $txn->If(Compare\CompareTarget::VERSION, Compare\CompareResult::EQUAL, 'key', 0));
        $this->assertSame($txn, $txn->Then(Txn::put('key', 'v')));
        $this->assertSame($txn, $txn->Else(Txn::get('key')));
    }

    public function testCompareFieldsAreSetCorrectly(): void
    {
        // Build a Compare through Txn and inspect the private compares via commit on a recording
        // stub. Instead, exercise the version path by verifying a real TxnRequest can be built.
        $kv  = (new \ReflectionClass(KVClient::class))->newInstanceWithoutConstructor();
        $txn = new Txn($kv);
        $txn->If(Compare\CompareTarget::VALUE, Compare\CompareResult::EQUAL, 'k', 'expected');

        $request    = new TxnRequest();
        $reflection = new \ReflectionProperty(Txn::class, 'compares');
        $compares   = $reflection->getValue($txn);
        $request->setCompare($compares);
        $this->assertCount(1, $request->getCompare());
        $compare = $request->getCompare()[0];
        $this->assertSame('k', $compare->getKey());
        $this->assertSame('expected', $compare->getValue());
        $this->assertSame(Compare\CompareResult::EQUAL, $compare->getResult());
        $this->assertSame(Compare\CompareTarget::VALUE, $compare->getTarget());
    }
}
