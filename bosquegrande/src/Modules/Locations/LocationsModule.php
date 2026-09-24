<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Locations;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;

if (!defined('ABSPATH')) exit;

/** Attaches an alternative region taxonomy to events when one is configured. */
final class LocationsModule implements ModuleInterface
{
    public function name(): string
    {
        return 'locations';
    }

    public function label(): string
    {
        return 'Event locations';
    }

    public function activate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        add_action('init', static function (): void {
            $tax = Taxonomy::taxonomy();
            if ($tax !== Taxonomy::DEFAULT_TAX) register_taxonomy_for_object_type($tax, Taxonomy::CPT);
        }, 30);
    }

    public function deactivate(Container $container): void
    {
    }
}
