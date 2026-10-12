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

class Cluster
{
    private array $nodes = [];

    public function add(string $host, int $port, int|float $weight = 100): void
    {
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            throw new Exception("Bad IP Address [{$host}]");
        }
        if ($port < 1 || $port > 65535) {
            throw new Exception("Bad Port [{$port}]");
        }
        if (!is_finite((float) $weight) || $weight < 0 || $weight > 65535) {
            throw new Exception("Bad Weight [{$weight}]");
        }
        $key = bin2hex(inet_pton($host)) . ':' . $port;
        if ($weight === 0 || $weight === 0.0) {
            unset($this->nodes[$key]);
            return;
        }
        $this->nodes[$key] = ['host' => $host, 'port' => $port, 'weight' => $weight];
    }

    /** Remove one node by weight, without replacement. A zero weight disables a node. */
    public function pop(): array|false
    {
        if ($this->nodes === []) {
            return false;
        }
        $total  = array_sum(array_column($this->nodes, 'weight'));
        $target = random_int(0, 2147483647) / 2147483648 * $total;
        foreach ($this->nodes as $key => $node) {
            $target -= $node['weight'];
            if ($target < 0) {
                unset($this->nodes[$key]);
                return $node;
            }
        }
        // Floating-point rounding must not make a nonempty cluster appear exhausted.
        $key  = array_key_last($this->nodes);
        $node = $this->nodes[$key];
        unset($this->nodes[$key]);
        return $node;
    }

    public function count(): int
    {
        return count($this->nodes);
    }

    public function getNodes(): array
    {
        return array_values($this->nodes);
    }
}
