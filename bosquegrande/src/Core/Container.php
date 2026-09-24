<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * Minimal lazy service container. Factories run once on first get();
 * results are cached. Enough DI for a plugin, no library weight.
 */
final class Container
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->resolved[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new \RuntimeException("BosqueGrande container: unknown service '$id'.");
        }
        return $this->resolved[$id] = ($this->factories[$id])($this);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->resolved);
    }
}
