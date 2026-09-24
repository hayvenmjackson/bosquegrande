<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * Contract for BosqueGrande modules. Lifecycle: activate() on plugin
 * activation, boot() on every plugins_loaded, deactivate() on plugin
 * deactivation. Modules resolve shared services from the container and
 * must not assume other modules booted first.
 */
interface ModuleInterface
{
    /** Stable snake_case identifier (menu slugs, log channels). */
    public function name(): string;

    /** Human-readable label for the admin UI. */
    public function label(): string;

    public function activate(Container $container): void;

    public function boot(Container $container): void;

    public function deactivate(Container $container): void;
}
