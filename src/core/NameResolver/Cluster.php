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

use Swoole\Exception;

class Cluster
{
    private array $nodes = [];

    /**
     * @throws Exception
     */
    public function add(string $host, int $port, int $weight = 100): void
    {
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            throw new Exception("Bad IP Address [{$host}]");
        }
        if ($port < 0 || $port > 65535) {
            throw new Exception("Bad Port [{$port}]");
        }
        if ($weight < 0 || $weight > 100) {
            throw new Exception("Bad Weight [{$weight}]");
        }
        $this->nodes[] = ['host' => $host, 'port' => $port, 'weight' => $weight];
    }

    /**
     * Remove and return a random node, or false if the cluster is empty.
     *
     * @return array|false a node as ['host' => string, 'port' => int, 'weight' => int], or false
     */
    public function pop()
    {
        if (empty($this->nodes)) {
            return false;
        }
        $index = array_rand($this->nodes, 1);
        $node  = $this->nodes[$index];
        unset($this->nodes[$index]);
        return $node;
    }

    public function count(): int
    {
        return count($this->nodes);
    }
}
