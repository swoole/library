<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Etcdserverpb;

/**
 */
class ClusterClient extends \Swoole\Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Swoole\Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts = []) {
        parent::__construct($hostname, $opts);
    }

    /**
     * MemberAdd adds a member into the cluster.
     * @param \Etcdserverpb\MemberAddRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\MemberAddResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function MemberAdd(\Etcdserverpb\MemberAddRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Cluster/MemberAdd',
        $argument,
        ['\Etcdserverpb\MemberAddResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * MemberRemove removes an existing member from the cluster.
     * @param \Etcdserverpb\MemberRemoveRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\MemberRemoveResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function MemberRemove(\Etcdserverpb\MemberRemoveRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Cluster/MemberRemove',
        $argument,
        ['\Etcdserverpb\MemberRemoveResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * MemberUpdate updates the member configuration.
     * @param \Etcdserverpb\MemberUpdateRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\MemberUpdateResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function MemberUpdate(\Etcdserverpb\MemberUpdateRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Cluster/MemberUpdate',
        $argument,
        ['\Etcdserverpb\MemberUpdateResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * MemberList lists all the members in the cluster.
     * @param \Etcdserverpb\MemberListRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\MemberListResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function MemberList(\Etcdserverpb\MemberListRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Cluster/MemberList',
        $argument,
        ['\Etcdserverpb\MemberListResponse', 'decode'],
        $metadata, $options);
    }

}
