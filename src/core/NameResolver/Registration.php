<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\NameResolver;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\NameResolver;

/** Owns one registration and its serial renewal loop. Call stop() before leaving the event loop. */
final class Registration
{
    private NameResolver $resolver;

    private Channel $wake;

    private Channel $done;

    private bool $running = false;

    private bool $registered = false;

    private bool $stopping = false;

    private int $pid;

    private ?\Throwable $lastError = null;

    private ?float $lastHeartbeat = null;

    private $errorHandler;

    private int $renewalCid = -1;

    private float $delay;

    public function __construct(NameResolver $resolver, private string $name, private string $ip,
        private int $port, private array $options, private float $interval)
    {
        if (Coroutine::getCid() < 0) {
            throw new \LogicException('Managed registration requires a coroutine');
        }
        $this->resolver = clone $resolver;
        $this->pid      = getmypid();
        $this->wake     = new Channel(1);
        $this->done     = new Channel(1);
        $this->delay    = $interval;
        try {
            if (!$this->resolver->join($name, $ip, $port, $options)) {
                throw new Exception('Service registration failed');
            }
            $this->registered = true;
            $this->renew();
            $this->running = true;
            if (Coroutine::create($this->run(...)) === false) {
                throw new Exception('Unable to start service heartbeat');
            }
        } catch (\Throwable $e) {
            $this->running = false;
            if ($this->registered) {
                try {
                    $this->resolver->leave($name, $ip, $port);
                } catch (\Throwable) {
                }
            }
            throw $e;
        }
    }

    public function __clone()
    {
        throw new \LogicException('A registration cannot be cloned');
    }

    public function __serialize(): array
    {
        throw new \LogicException('A registration cannot be serialized');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('A registration cannot be unserialized');
    }

    public function onError(callable $handler): self
    {
        $this->errorHandler = $handler;
        return $this;
    }

    public function isRunning(): bool
    {
        return $this->running && $this->pid === getmypid();
    }

    public function getLastError(): ?\Throwable
    {
        return $this->lastError;
    }

    public function getLastHeartbeat(): ?float
    {
        return $this->lastHeartbeat;
    }

    public function stop(): bool
    {
        // A forked child never owns the parent's service lease.
        if ($this->pid !== getmypid()) {
            $this->running = $this->registered = false;
            return true;
        }
        if (!$this->registered) {
            return true;
        }
        if (Coroutine::getCid() < 0) {
            throw new \LogicException('Stopping registration requires a coroutine');
        }
        $this->stopRenewal();
        if (Coroutine::getCid() !== $this->renewalCid) {
            $this->done->pop();
        }
        return $this->deregister();
    }

    /** Stop future heartbeats without removing an endpoint now owned by a replacement worker. */
    public function stopRenewal(): void
    {
        $this->running = false;
        if ($this->pid === getmypid()) {
            $this->wake->close();
        }
    }

    private function deregister(): bool
    {
        while ($this->stopping) {
            Coroutine::sleep(0.001);
        }
        if (!$this->registered) {
            return true;
        }
        $this->stopping = true;
        try {
            if (!$this->resolver->leave($this->name, $this->ip, $this->port)) {
                throw new Exception('Service deregistration failed');
            }
            $this->registered = false;
            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e;
            throw $e;
        } finally {
            $this->stopping = false;
        }
    }

    private function renew(): void
    {
        $result = $this->resolver->heartbeat($this->name, $this->ip, $this->port, $this->options);
        if ($result === false) {
            throw new Exception('Service heartbeat failed');
        }
        if (is_float($result) || is_int($result)) {
            if (!is_finite((float) $result) || $result <= 0) {
                throw new Exception('Invalid service heartbeat interval');
            }
            $this->delay = max(0.001, min($this->interval, $result));
        }
        $this->lastHeartbeat = microtime(true);
        $this->lastError     = null;
    }

    private function run(): void
    {
        $this->renewalCid = Coroutine::getCid();
        try {
            while ($this->isRunning()) {
                $this->wake->pop($this->delay);
                $this->heartbeatIfRunning();
            }
        } finally {
            $this->running    = false;
            $this->renewalCid = -1;
            $this->done->close();
        }
    }

    private function heartbeatIfRunning(): void
    {
        if (!$this->isRunning()) {
            return;
        }
        try {
            try {
                $this->renew();
            } catch (Exception $e) {
                $this->recover($e);
            }
        } catch (\Throwable $e) {
            $this->lastError = $e;
            if ($this->errorHandler !== null) {
                try {
                    ($this->errorHandler)($e);
                } catch (\Throwable $observerError) {
                    $this->lastError = $observerError;
                }
            }
        }
    }

    private function recover(Exception $error): void
    {
        if (!$this->isRunning() || !in_array($error->getCode(), [404, 20404], true)) {
            throw $error;
        }
        if (!$this->resolver->join($this->name, $this->ip, $this->port, $this->options)) {
            throw $error;
        }
        $this->renewIfRunning();
    }

    private function renewIfRunning(): void
    {
        if ($this->isRunning()) {
            $this->renew();
        }
    }
}
