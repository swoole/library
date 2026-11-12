<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc\Example\Etcd;

use Etcdserverpb\Compare;
use Etcdserverpb\DeleteRangeRequest;
use Etcdserverpb\KVClient;
use Etcdserverpb\PutRequest;
use Etcdserverpb\RangeRequest;
use Etcdserverpb\RequestOp;
use Etcdserverpb\TxnRequest;
use Swoole\Grpc\Result;

/**
 * Fluent etcd transaction builder (jetcd Txn / clientv3 Txn style): If(compare...).Then(op...)
 * .Else(op...).commit(). Compare targets/results use the generated enum constants.
 */
final class Txn
{
    private KVClient $kv;

    /** @var array<int, Compare> */
    private array $compares = [];

    /** @var array<int, RequestOp> */
    private array $thenOps = [];

    /** @var array<int, RequestOp> */
    private array $elseOps = [];

    public function __construct(KVClient $kv)
    {
        $this->kv = $kv;
    }

    /**
     * Add a comparison, e.g. If(self::version('key'), EQUAL, 0) for create-if-absent.
     *
     * @param int $target Compare\CompareTarget::* constant
     * @param int $result Compare\CompareResult::* constant
     * @param mixed $value
     */
    public function If(int $target, int $result, string $key, $value = null): self
    {
        $compare = new Compare();
        $compare->setResult($result);
        $compare->setTarget($target);
        $compare->setKey($key);
        switch ($target) {
            case Compare\CompareTarget::VERSION:
                $compare->setVersion((int) $value);
                break;
            case Compare\CompareTarget::CREATE:
                $compare->setCreateRevision((int) $value);
                break;
            case Compare\CompareTarget::MOD:
                $compare->setModRevision((int) $value);
                break;
            case Compare\CompareTarget::VALUE:
                $compare->setValue((string) $value);
                break;
            case Compare\CompareTarget::LEASE:
                $compare->setLease((int) $value);
                break;
        }
        $this->compares[] = $compare;
        return $this;
    }

    public function Then(RequestOp ...$ops): self
    {
        foreach ($ops as $op) {
            $this->thenOps[] = $op;
        }
        return $this;
    }

    public function Else(RequestOp ...$ops): self
    {
        foreach ($ops as $op) {
            $this->elseOps[] = $op;
        }
        return $this;
    }

    public function commit(): Result
    {
        $request = new TxnRequest();
        $request->setCompare($this->compares);
        $request->setSuccess($this->thenOps);
        $request->setFailure($this->elseOps);
        return Result::fromTriple($this->kv->Txn($request));
    }

    // ---- RequestOp factories -------------------------------------------------

    public static function put(string $key, string $value, int $leaseId = 0): RequestOp
    {
        $put = new PutRequest();
        $put->setKey($key);
        $put->setValue($value);
        if ($leaseId > 0) {
            $put->setLease($leaseId);
        }
        $op = new RequestOp();
        $op->setRequestPut($put);
        return $op;
    }

    public static function get(string $key): RequestOp
    {
        $range = new RangeRequest();
        $range->setKey($key);
        $op = new RequestOp();
        $op->setRequestRange($range);
        return $op;
    }

    public static function delete(string $key): RequestOp
    {
        $delete = new DeleteRangeRequest();
        $delete->setKey($key);
        $op = new RequestOp();
        $op->setRequestDeleteRange($delete);
        return $op;
    }
}
