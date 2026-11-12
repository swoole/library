<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Etcdserverpb;

/**
 */
class WatchClient extends \Swoole\Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Swoole\Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts = []) {
        parent::__construct($hostname, $opts);
    }

    /**
     * Watch watches for events happening or that have happened. Both input and output
     * are streams; the input stream is for creating and canceling watchers and the output
     * stream sends events. One watch RPC can watch on multiple key ranges, streaming events
     * for several watches at once. The entire event history can be watched starting from the
     * last compaction revision.
     * @param array $metadata metadata
     * @param array $options call options
     * @return bool|\Swoole\Grpc\BidiStreamingCall
     */
    public function Watch($metadata = [], $options = []) {
        return $this->_bidiRequest('/etcdserverpb.Watch/Watch',
        ['\Etcdserverpb\WatchResponse','decode'],
        $metadata, $options);
    }

}
