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
use Swoole\Server;

/** Framework integration; application code only configures service_name and a resolver. */
final class ServiceRegistration
{
    private static ?\WeakMap $servers = null;

    private Channel $wake;

    private ?Registration $registration = null;

    private bool $deregister = false;

    private bool $stopped = false;

    private bool $active = true;

    private function __construct(private array $config)
    {
        $this->wake = new Channel(1);
    }

    public static function configuration(object $server, array $settings): ?array
    {
        if (!array_key_exists('service_name', $settings)) {
            return null;
        }
        $name = $settings['service_name'];
        if (!is_string($name) || $name === '' || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('service_name must be a nonempty string');
        }
        $resolver = $settings['service_resolver'] ?? null;
        if ($resolver === null) {
            $candidates = $settings['name_resolver'] ?? (Coroutine::getOptions()['name_resolver'] ?? []);
            $candidates = array_values(array_filter($candidates, static function ($candidate) use ($name): bool {
                return $candidate instanceof NameResolver && (!$candidate->hasFilter() || ($candidate->getFilter())($name) === true);
            }));
            if (count($candidates) !== 1) {
                throw new \InvalidArgumentException('service_name requires exactly one matching resolver or an explicit service_resolver');
            }
            $resolver = $candidates[0];
        }
        if (!$resolver instanceof NameResolver) {
            throw new \InvalidArgumentException('service_resolver must be a NameResolver');
        }
        $ip       = $settings['service_address'] ?? $server->host;
        $port     = $settings['service_port'] ?? $server->port;
        $options  = $settings['service_options'] ?? [];
        $interval = $settings['service_heartbeat_interval'] ?? 5.0;
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP) || in_array($ip, ['0.0.0.0', '::'], true)) {
            throw new \InvalidArgumentException('Set service_address to a reachable IP when listening on a wildcard or Unix socket');
        }
        if (!is_int($port) || $port < 1 || $port > 65535 || !is_array($options)
            || (!is_int($interval) && !is_float($interval)) || !is_finite((float) $interval) || $interval <= 0 || $interval > 86400) {
            throw new \InvalidArgumentException('Invalid service port, options or heartbeat interval');
        }
        return [clone $resolver, $name, $ip, $port, $options, (float) $interval];
    }

    public static function start(object $server, array $settings): ?Registration
    {
        $config = self::configuration($server, $settings);
        if ($config === null) {
            return null;
        }
        [$resolver, $name, $ip, $port, $options, $interval] = $config;
        return $resolver->register($name, $ip, $port, $options, $interval)->onError(self::report(...));
    }

    /** Called by Server\Helper without requiring a user onStart callback. */
    public static function startServer(Server $server): void
    {
        $config = self::configuration($server, $server->setting);
        if ($config === null) {
            return;
        }
        self::$servers ??= new \WeakMap();
        $existing = self::$servers[$server] ?? null;
        if ($existing !== null && $existing->isActive()) {
            return;
        }
        $owner                  = new self($config);
        self::$servers[$server] = $owner;
        if (Coroutine::create($owner->run(...)) === false) {
            unset(self::$servers[$server]);
            throw new Exception('Unable to start automatic service registration');
        }
    }

    public static function stopServer(Server $server, bool $deregister = true): void
    {
        $owner = self::$servers[$server] ?? null;
        if ($owner === null) {
            return;
        }
        $owner->deregister = $owner->deregister || $deregister;
        $owner->stopped    = true;
        $owner->registration?->stopRenewal();
        $owner->wake->close();
    }

    /** BASE workers also receive beforeShutdown while reloading; only the manager owns final removal. */
    public static function shutdownServer(Server $server): void
    {
        if ($server->mode !== SWOOLE_BASE || ($server->manager_pid !== 0 && $server->manager_pid !== getmypid())) {
            return;
        }
        try {
            $config = self::configuration($server, $server->setting);
            if ($config !== null) {
                [$resolver, $name, $ip, $port] = $config;
                $resolver->leave($name, $ip, $port);
            }
        } catch (\Throwable $error) {
            self::report($error);
        }
    }

    private function isActive(): bool
    {
        return $this->active && !$this->stopped
            && ($this->registration === null || $this->registration->isRunning());
    }

    private function run(): void
    {
        [$resolver, $name, $ip, $port, $options, $interval] = $this->config;
        try {
            // Initial outages are retried while the server is alive, without a busy loop.
            while (!$this->stopped) {
                try {
                    $this->registration = $resolver->register($name, $ip, $port, $options, $interval)->onError(self::report(...));
                    break;
                } catch (\Throwable $error) {
                    self::report($error);
                    $this->wake->pop($interval);
                }
            }
            if ($this->registration !== null) {
                $this->wake->pop();
            }
        } finally {
            $this->active = false;
            $this->finish();
        }
    }

    private function finish(): void
    {
        if ($this->registration === null) {
            return;
        }
        try {
            if ($this->deregister) {
                $this->registration->stop();
            } else {
                // BASE worker reload: its successor owns the same listening endpoint.
                $this->registration->stopRenewal();
            }
        } catch (\Throwable $error) {
            self::report($error);
        }
    }

    private static function report(\Throwable $error): void
    {
        // Error handlers are application-controlled and must not kill the heartbeat owner.
        try {
            trigger_error('Service registration: ' . $error->getMessage(), E_USER_WARNING);
        } catch (\Throwable) {
        }
    }
}
