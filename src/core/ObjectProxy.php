<?php
declare(strict_types=1);

namespace Swoole;

use TypeError;

class ObjectProxy
{
    /** @var object */
    protected $object;

    public function __construct($object)
    {
        if (!is_object($object)) {
            throw new TypeError('Non-object given');
        }
        $this->object = $object;
    }

    public function __getObject()
    {
        return $this->object;
    }

    public function __get(string $name)
    {
        return $this->object->$name;
    }

    public function __set(string $name, $value)
    {
        $this->object->$name = $value;
    }

    public function __isset($name)
    {
        return isset($this->object->$name);
    }

    public function __unset(string $name): void
    {
        unset($this->object->$name);
    }

    public function __call(string $name, array $arguments)
    {
        return $this->object->$name(...$arguments);
    }

    public function __invoke(...$arguments)
    {
        /** @var mixed $connection */
        $connection = $this->object;
        return $connection(...$arguments);
    }
}
