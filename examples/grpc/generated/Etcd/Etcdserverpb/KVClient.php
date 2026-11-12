<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Etcdserverpb;

/**
 */
class KVClient extends \Swoole\Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Swoole\Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts = []) {
        parent::__construct($hostname, $opts);
    }

    /**
     * Range gets the keys in the range from the key-value store.
     * @param \Etcdserverpb\RangeRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\RangeResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function Range(\Etcdserverpb\RangeRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.KV/Range',
        $argument,
        ['\Etcdserverpb\RangeResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Put puts the given key into the key-value store.
     * A put request increments the revision of the key-value store
     * and generates one event in the event history.
     * @param \Etcdserverpb\PutRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\PutResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function Put(\Etcdserverpb\PutRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.KV/Put',
        $argument,
        ['\Etcdserverpb\PutResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * DeleteRange deletes the given range from the key-value store.
     * A delete request increments the revision of the key-value store
     * and generates a delete event in the event history for every deleted key.
     * @param \Etcdserverpb\DeleteRangeRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\DeleteRangeResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function DeleteRange(\Etcdserverpb\DeleteRangeRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.KV/DeleteRange',
        $argument,
        ['\Etcdserverpb\DeleteRangeResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Txn processes multiple requests in a single transaction.
     * A txn request increments the revision of the key-value store
     * and generates events with the same revision for every completed request.
     * It is not allowed to modify the same key several times within one txn.
     * @param \Etcdserverpb\TxnRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\TxnResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function Txn(\Etcdserverpb\TxnRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.KV/Txn',
        $argument,
        ['\Etcdserverpb\TxnResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Compact compacts the event history in the etcd key-value store. The key-value
     * store should be periodically compacted or the event history will continue to grow
     * indefinitely.
     * @param \Etcdserverpb\CompactionRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\CompactionResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function Compact(\Etcdserverpb\CompactionRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.KV/Compact',
        $argument,
        ['\Etcdserverpb\CompactionResponse', 'decode'],
        $metadata, $options);
    }

}
