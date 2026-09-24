<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * Holds the module list in registration order and fans out lifecycle
 * calls. A module that throws is logged and skipped so one bad module
 * cannot take the whole site down.
 */
final class ModuleRegistry
{
    /** @var ModuleInterface[] */
    private array $modules = [];

    public function __construct(private Container $container)
    {
    }

    public function add(ModuleInterface $module): void
    {
        $this->modules[] = $module;
    }

    /** @return ModuleInterface[] */
    public function all(): array
    {
        return $this->modules;
    }

    public function activate(): void
    {
        $this->fanOut('activate');
    }

    public function boot(): void
    {
        $this->fanOut('boot');
    }

    public function deactivate(): void
    {
        $this->fanOut('deactivate');
    }

    private function fanOut(string $method): void
    {
        $failed = [];
        foreach ($this->modules as $module) {
            try {
                $module->{$method}($this->container);
            } catch (\Throwable $e) {
                /** @var Logger $logger */
                $logger = $this->container->get('logger');
                $logger->error('modules', sprintf(
                    'Module %s failed during %s: %s',
                    $module->name(),
                    $method,
                    $e->getMessage()
                ));
                if ($method === 'boot') {
                    $failed[$module->label()] = $e->getMessage();
                }
            }
        }
        // Persist boot failures so the administrator sees them in addition
        // to the structured log entry.
        if ($method === 'boot' && get_option('bg_boot_failures', []) !== $failed) {
            update_option('bg_boot_failures', $failed, false);
        }
    }
}
