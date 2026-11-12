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

use Etcdserverpb\ResponseHeader;
use Etcdserverpb\WatchClient;
use Etcdserverpb\WatchResponse;
use Mvccpb\Event;
use Mvccpb\KeyValue;
use Swoole\Grpc\Example\Etcd\Watcher;
use Swoole\Grpc\Status\StatusCode;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Grpc\Example\Etcd\Watcher
 * @covers \Swoole\Grpc\Example\Etcd\WatchEvent
 */
class WatcherTest extends TestCase
{
    public function testConsumeAdvancesRevisionFromHeader(): void
    {
        $watcher  = $this->watcher();
        $response = $this->response(rev: 10, events: []);
        $this->assertTrue($watcher->consume($response));
        $this->assertSame(10, $watcher->getLastRevision());
        $this->assertSame(11, $watcher->nextStartRevision());
    }

    public function testConsumeCollectsEventsAndUsesMaxRevision(): void
    {
        $watcher  = $this->watcher(startRevision: 5);
        $response = $this->response(rev: 8, events: [
            $this->event('key1', 'v1', 9),
            $this->event('key2', 'v2', 12),
        ]);
        $this->assertTrue($watcher->consume($response));
        $this->assertSame(12, $watcher->getLastRevision());
        $events = $watcher->getEvents();
        $this->assertCount(2, $events);
        $this->assertSame('key1', $events[0]->getKey());
        $this->assertTrue($events[0]->isPut());
        $this->assertSame('v2', $events[1]->getValue());
        $this->assertSame(12, $events[1]->getModRevision());
    }

    public function testDeleteEventType(): void
    {
        $watcher = $this->watcher();
        $watcher->consume($this->response(rev: 3, events: [$this->event('k', '', 3, Event\EventType::DELETE)]));
        $this->assertTrue($watcher->getEvents()[0]->isDelete());
    }

    public function testCompactedStopsStream(): void
    {
        $watcher  = $this->watcher();
        $response = $this->response(rev: 0, events: []);
        $response->setCompactRevision(7);
        $this->assertFalse($watcher->consume($response));
        $this->assertSame(7, $watcher->getCompactRevision());
    }

    public function testCanceledStopsStream(): void
    {
        $watcher  = $this->watcher();
        $response = $this->response(rev: 4, events: []);
        $response->setCanceled(true);
        $this->assertFalse($watcher->consume($response));
    }

    public function testNonObjectStopsStream(): void
    {
        $watcher = $this->watcher();
        $this->assertFalse($watcher->consume(null));
    }

    public function testBuildCreateRequestResumesFromNextRevision(): void
    {
        $watcher = $this->watcher();
        $watcher->consume($this->response(rev: 20, events: []));
        $request = $watcher->buildCreateRequest();
        $create  = $request->getCreateRequest();
        $this->assertSame('foo', $create->getKey());
        $this->assertSame(21, $create->getStartRevision());
        $this->assertTrue($create->getProgressNotify());
    }

    public function testPrefixWatchSetsRangeEnd(): void
    {
        $watcher = $this->watcher(prefix: true);
        $create  = $watcher->buildCreateRequest()->getCreateRequest();
        $this->assertSame('fop', $create->getRangeEnd()); // prefixEnd("foo")
    }

    public function testIsRecoverable(): void
    {
        $this->assertTrue(Watcher::isRecoverable(StatusCode::UNAVAILABLE));
        $this->assertTrue(Watcher::isRecoverable(StatusCode::DEADLINE_EXCEEDED));
        $this->assertFalse(Watcher::isRecoverable(StatusCode::OK));
        $this->assertFalse(Watcher::isRecoverable(StatusCode::CANCELLED));
        $this->assertFalse(Watcher::isRecoverable(StatusCode::INVALID_ARGUMENT));
    }

    private function watcher(bool $prefix = false, int $startRevision = 0): Watcher
    {
        // WatchClient is never used by the pure-state methods under test.
        return new Watcher($this->createStubWatchClient(), 'foo', $prefix, $startRevision);
    }

    private function createStubWatchClient(): WatchClient
    {
        // Generated stub constructor would connect; use reflection to bypass for pure tests.
        return (new \ReflectionClass(WatchClient::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param array<int, Event> $events
     */
    private function response(int $rev, array $events): WatchResponse
    {
        $response = new WatchResponse();
        $header   = new ResponseHeader();
        $header->setRevision($rev);
        $response->setHeader($header);
        $response->setEvents($events);
        return $response;
    }

    private function event(string $key, string $value, int $modRevision, int $type = Event\EventType::PUT): Event
    {
        $kv = new KeyValue();
        $kv->setKey($key);
        $kv->setValue($value);
        $kv->setModRevision($modRevision);
        $event = new Event();
        $event->setType($type);
        $event->setKv($kv);
        return $event;
    }
}
