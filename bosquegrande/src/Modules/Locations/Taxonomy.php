<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Locations;

if (!defined('ABSPATH')) exit;

/**
 * Which taxonomy holds event regions. By default regions are bg_tag terms
 * alongside interests; a site that keeps places in another taxonomy
 * attached to bg_event can point BosqueGrande at it with the
 * bg_location_taxonomy filter.
 */
final class Taxonomy
{
    public const CPT = 'bg_event';
    public const DEFAULT_TAX = 'bg_tag';

    public static function taxonomy(): string
    {
        $tax = (string) apply_filters('bg_location_taxonomy', self::DEFAULT_TAX);
        return $tax !== '' && ($tax === self::DEFAULT_TAX || taxonomy_exists($tax)) ? $tax : self::DEFAULT_TAX;
    }
}
