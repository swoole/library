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

use Etcdserverpb\WatchClient;
use Etcdserverpb\WatchCreateRequest;
use Etcdserverpb\WatchRequest;
use Swoole\Grpc\Status\StatusCode;

/**
 * A resilient watch over a key or prefix, modeled on clientv3's watcher. It reconnects on
 * recoverable errors and resumes from the last observed revision + 1 (start_revision), requests
 * progress notifications so a quiet prefix still advances the revision, and surfaces
 * compaction (which forces the caller to re-list and re-watch).
 *
 * The event parsing and revision bookkeeping are pure and unit-testable; the I/O loop is
 * intentionally thin.
 */
final class Watcher
{
    private WatchClient $client;

    private string $key;

    private bool $prefix;

    private int $startRevision;

    private bool $progressNotify;

    /** @var array<int, WatchEvent> */
    private array $events = [];

    private int $lastRevision;

    private int $compactRevision = 0;

    public function __construct(WatchClient $client, string $key, bool $prefix = false, int $startRevision = 0, bool $progressNotify = true)
    {
        $this->client         = $client;
        $this->key            = $key;
        $this->prefix         = $prefix;
        $this->startRevision  = $startRevision;
        $this->progressNotify = $progressNotify;
        $this->lastRevision   = $startRevision > 0 ? $startRevision - 1 : 0;
    }

    public function getLastRevision(): int
    {
        return $this->lastRevision;
    }

    public function getCompactRevision(): int
    {
        return $this->compactRevision;
    }

    /**
     * @return array<int, WatchEvent>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * The revision to resume from on the next (re)connect.
     */
    public function nextStartRevision(): int
    {
        return $this->lastRevision + 1;
    }

    /**
     * Build the WatchRequest used to (re)establish the stream. Exposed for testing.
     */
    public function buildCreateRequest(): WatchRequest
    {
        $create = new WatchCreateRequest();
        $create->setKey($this->key);
        if ($this->prefix) {
            $create->setRangeEnd(Client::prefixEnd($this->key));
        }
        if ($this->nextStartRevision() > 1) {
            $create->setStartRevision($this->nextStartRevision());
        }
        $create->setProgressNotify($this->progressNotify);

        $request = new WatchRequest();
        $request->setCreateRequest($create);
        return $request;
    }

    /**
     * Fold one WatchResponse into the event/revision state. Pure (no I/O), returns whether the
     * stream should keep going. A compacted watch must be re-established by the caller.
     *
     * @param mixed $response an Etcdserverpb\WatchResponse-like object
     */
    public function consume($response): bool
    {
        if (!is_object($response)) {
            return false;
        }

        $compact = (int) $response->getCompactRevision();
        if ($compact > 0) {
            // History we need is gone: the caller must re-list from the current state.
            $this->compactRevision = $compact;
            return false;
        }

        $header = $response->getHeader();
        if ($header !== null) {
            $revision = (int) $header->getRevision();
            if ($revision > $this->lastRevision) {
                $this->lastRevision = $revision;
            }
        }

        foreach ($response->getEvents() as $event) {
            $kv             = $event->getKv();
            $this->events[] = new WatchEvent(
                $kv ? (string) $kv->getKey() : '',
                $kv ? (string) $kv->getValue() : '',
                (int) $event->getType(),
                $kv ? (int) $kv->getModRevision() : 0
            );
            $modRevision = $kv ? (int) $kv->getModRevision() : 0;
            if ($modRevision > $this->lastRevision) {
                $this->lastRevision = $modRevision;
            }
        }

        return !$response->getCanceled();
    }

    /**
     * Whether a status code is worth reconnecting for.
     */
    public static function isRecoverable(int $statusCode): bool
    {
        return $statusCode !== StatusCode::OK
            && $statusCode !== StatusCode::CANCELLED
            && StatusCode::isRetryable($statusCode);
    }

    /**
     * Run the watch loop until $maxReconnects. The callback receives each batch of events after
     * a response; return false from it to stop. Uses the streaming iterator from the runtime and
     * resumes from nextStartRevision() after a recoverable disconnect.
     *
     * @param callable(array<int, WatchEvent>): bool $onEvents
     */
    public function run(callable $onEvents, int $maxReconnects = 5): void
    {
        for ($reconnects = 0; $reconnects <= $maxReconnects; $reconnects++) {
            $call = $this->client->Watch();
            $call->push($this->buildCreateRequest());

            foreach ($call as $response) {
                if (!is_object($response)) {
                    continue;
                }
                $before    = count($this->events);
                $keepGoing = $this->consume($response);
                $batch     = array_slice($this->events, $before);
                if ($batch !== [] && $onEvents($batch) === false) {
                    $call->cancel();
                    return;
                }
                if (!$keepGoing) {
                    break;
                }
            }

            $status = $call->getStatus();
            $call->cancel();

            // Compaction cannot be resumed by revision; the caller must re-list.
            if ($this->compactRevision > 0) {
                return;
            }
            // A healthy end-of-stream (OK / explicit cancel) stops; a transient failure retries.
            if (!self::isRecoverable($status->getCode()) || $reconnects >= $maxReconnects) {
                return;
            }
        }
    }
}
