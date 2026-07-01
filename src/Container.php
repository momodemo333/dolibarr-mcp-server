<?php

declare(strict_types=1);

namespace DolibarrMcp;

use Psr\Container\ContainerInterface;
use RuntimeException;

class Container implements ContainerInterface
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * @param callable $factory
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function setInstance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            $this->instances[$id] = ($this->factories[$id])($this);
            return $this->instances[$id];
        }

        throw new RuntimeException("Service not found: {$id}");
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }
}
