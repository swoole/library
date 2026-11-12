<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Etcdserverpb;

/**
 */
class MaintenanceClient extends \Swoole\Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Swoole\Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts = []) {
        parent::__construct($hostname, $opts);
    }

    /**
     * Alarm activates, deactivates, and queries alarms regarding cluster health.
     * @param \Etcdserverpb\AlarmRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AlarmResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function Alarm(\Etcdserverpb\AlarmRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Maintenance/Alarm',
        $argument,
        ['\Etcdserverpb\AlarmResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Status gets the status of the member.
     * @param \Etcdserverpb\StatusRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\StatusResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function Status(\Etcdserverpb\StatusRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Maintenance/Status',
        $argument,
        ['\Etcdserverpb\StatusResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Defragment defragments a member's backend database to recover storage space.
     * @param \Etcdserverpb\DefragmentRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\DefragmentResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function Defragment(\Etcdserverpb\DefragmentRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Maintenance/Defragment',
        $argument,
        ['\Etcdserverpb\DefragmentResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Hash computes the hash of whole backend keyspace,
     * including key, lease, and other buckets in storage.
     * This is designed for testing ONLY!
     * Do not rely on this in production with ongoing transactions,
     * since Hash operation does not hold MVCC locks.
     * Use "HashKV" API instead for "key" bucket consistency checks.
     * @param \Etcdserverpb\HashRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\HashResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function Hash(\Etcdserverpb\HashRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Maintenance/Hash',
        $argument,
        ['\Etcdserverpb\HashResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * HashKV computes the hash of all MVCC keys up to a given revision.
     * It only iterates "key" bucket in backend storage.
     * @param \Etcdserverpb\HashKVRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\HashKVResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function HashKV(\Etcdserverpb\HashKVRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Maintenance/HashKV',
        $argument,
        ['\Etcdserverpb\HashKVResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Snapshot sends a snapshot of the entire backend from a member over a stream to a client.
     * @param \Etcdserverpb\SnapshotRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     */
    public function Snapshot(\Etcdserverpb\SnapshotRequest $argument,
      $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/etcdserverpb.Maintenance/Snapshot',
        $argument,
        ['\Etcdserverpb\SnapshotResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * MoveLeader requests current leader node to transfer its leadership to transferee.
     * @param \Etcdserverpb\MoveLeaderRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\MoveLeaderResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function MoveLeader(\Etcdserverpb\MoveLeaderRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Maintenance/MoveLeader',
        $argument,
        ['\Etcdserverpb\MoveLeaderResponse', 'decode'],
        $metadata, $options);
    }

}
