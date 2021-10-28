<?php

namespace Swoole\NameService;

use Swoole\Exception;

class Cluster
{
    private array $nodes = [];

    public function add(string $host, int $port, int $weight = 100): void
    {
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            throw new Exception("Bad IP Address [$host]");
        }
        if ($port < 0 or $port > 65535) {
            throw new Exception("Bad Port [$port]");
        }
        if ($weight < 0 or $weight > 100) {
            throw new Exception("Bad Weight [$weight]");
        }
        $this->nodes[] = ['host' => $host, 'port' => $port, 'weight' => $weight];
    }

    public function pop()
    {
        if (empty($this->nodes)) {
            return false;
        }
        $index = array_rand($this->nodes, 1);
        $node = $this->nodes[$index];
        unset($this->nodes[$index]);
        return $node;
    }

    public function count(): int
    {
        return count($this->nodes);
    }
}
