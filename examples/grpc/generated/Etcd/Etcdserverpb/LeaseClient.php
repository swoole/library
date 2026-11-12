<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Etcdserverpb;

/**
 */
class LeaseClient extends \Swoole\Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Swoole\Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts = []) {
        parent::__construct($hostname, $opts);
    }

    /**
     * LeaseGrant creates a lease which expires if the server does not receive a keepAlive
     * within a given time to live period. All keys attached to the lease will be expired and
     * deleted if the lease expires. Each expired key generates a delete event in the event history.
     * @param \Etcdserverpb\LeaseGrantRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\LeaseGrantResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function LeaseGrant(\Etcdserverpb\LeaseGrantRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Lease/LeaseGrant',
        $argument,
        ['\Etcdserverpb\LeaseGrantResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * LeaseRevoke revokes a lease. All keys attached to the lease will expire and be deleted.
     * @param \Etcdserverpb\LeaseRevokeRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\LeaseRevokeResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function LeaseRevoke(\Etcdserverpb\LeaseRevokeRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Lease/LeaseRevoke',
        $argument,
        ['\Etcdserverpb\LeaseRevokeResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * LeaseKeepAlive keeps the lease alive by streaming keep alive requests from the client
     * to the server and streaming keep alive responses from the server to the client.
     * @param array $metadata metadata
     * @param array $options call options
     * @return bool|\Swoole\Grpc\BidiStreamingCall
     */
    public function LeaseKeepAlive($metadata = [], $options = []) {
        return $this->_bidiRequest('/etcdserverpb.Lease/LeaseKeepAlive',
        ['\Etcdserverpb\LeaseKeepAliveResponse','decode'],
        $metadata, $options);
    }

    /**
     * LeaseTimeToLive retrieves lease information.
     * @param \Etcdserverpb\LeaseTimeToLiveRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\LeaseTimeToLiveResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function LeaseTimeToLive(\Etcdserverpb\LeaseTimeToLiveRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Lease/LeaseTimeToLive',
        $argument,
        ['\Etcdserverpb\LeaseTimeToLiveResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * LeaseLeases lists all existing leases.
     * @param \Etcdserverpb\LeaseLeasesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\LeaseLeasesResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function LeaseLeases(\Etcdserverpb\LeaseLeasesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Lease/LeaseLeases',
        $argument,
        ['\Etcdserverpb\LeaseLeasesResponse', 'decode'],
        $metadata, $options);
    }

}
