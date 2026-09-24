<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Revenue;

use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Constituents\RegionalGroups;

if (!defined('ABSPATH')) exit;

/**
 * QuickBooks Class for a payment, chosen from the payer's region.
 *
 * The region comes from the same rules as constituent regional groups
 * (rules only, no provider call) and is mapped to a Class on the Revenue
 * screen. A payment whose region has no Class, and no default Class is set,
 * waits in Review instead of being guessed.
 */
final class QboTransactionRegion
{
    public const REVIEW = 'review';
    private const OPTION = 'bg_qbo_region_classes';

    /**
     * @return array{slug:string,label:string,class:string,source:string}
     */
    public static function forConstituent(ConstituentStore $store, int $constituentId): array
    {
        $record = $constituentId > 0 ? $store->get($constituentId) : null;
        return self::forRecord(is_array($record) ? $record : []);
    }

    /**
     * @param array<string,mixed> $record
     * @return array{slug:string,label:string,class:string,source:string}
     */
    public static function forRecord(array $record): array
    {
        $decision = $record ? RegionalGroups::classifyRecord($record) : ['region' => '', 'evidence' => 'No payer record.'];
        $slug = (string) $decision['region'];
        $classes = self::classes();
        $class = $slug !== '' ? (string) ($classes['regions'][$slug] ?? '') : '';
        $source = (string) ($decision['evidence'] ?? '');
        if ($class === '' && $classes['default'] !== '') {
            $class = $classes['default'];
            $source = $slug === '' ? 'default Class (region unknown)' : 'default Class (no Class for this region)';
        }
        return [
            'slug' => $slug !== '' ? $slug : self::REVIEW,
            'label' => $slug !== '' ? RegionalGroups::label($slug) : 'Review needed',
            'class' => $class,
            'source' => $source,
        ];
    }

    /** @return array{regions: array<string,string>, default: string} */
    public static function classes(): array
    {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $regions = [];
        foreach ((array) ($saved['regions'] ?? []) as $slug => $class) {
            $class = sanitize_text_field((string) $class);
            if ($class !== '') $regions[sanitize_title((string) $slug)] = $class;
        }
        return ['regions' => $regions, 'default' => sanitize_text_field((string) ($saved['default'] ?? ''))];
    }

    /** @param array<string,string> $regions */
    public static function save(array $regions, string $default): void
    {
        $known = RegionalGroups::regions();
        $clean = [];
        foreach ($regions as $slug => $class) {
            $slug = sanitize_title((string) $slug);
            $class = sanitize_text_field((string) $class);
            if (isset($known[$slug]) && $class !== '') $clean[$slug] = bg_substr($class, 0, 190);
        }
        update_option(self::OPTION, [
            'regions' => $clean,
            'default' => bg_substr(sanitize_text_field($default), 0, 190),
        ], false);
    }
}
