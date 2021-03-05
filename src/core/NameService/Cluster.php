<?php

namespace Swoole\NameService;

class Cluster
{
    private $nodes = [];

    public function __construct($nodes)
    {
        $this->nodes = array_flip($nodes);
    }

    public function pop(bool $with_port)
    {
        if (empty($this->nodes)) {
            return false;
        }
        $index = array_rand($this->nodes, 1);
        unset($this->nodes[$index]);
        return $with_port ? $index : strstr($index, ':', true);
    }
}
