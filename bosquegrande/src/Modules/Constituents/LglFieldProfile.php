<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Constituents;

if (!defined('ABSPATH')) exit;

/**
 * Keeps local custom fields in step with the custom attributes defined in
 * a connected Little Green Light account. Additive and idempotent: it
 * creates missing local definitions and never deletes a field or value.
 */
final class LglFieldProfile
{
    /**
     * Compare the connected account's live custom-attribute schema
     * with BosqueGrande and add any definitions that do not exist
     * locally. This is deliberately one-way and additive: LGL is never
     * modified, and a local field is never deleted or renamed.
     *
     * Unknown LGL attributes default to sensitive. Every custom value
     * is encrypted either way, while this conservative flag also keeps
     * an unfamiliar field out of exports and assisted automation surfaces until an
     * administrator explicitly decides otherwise.
     *
     * @param array<int, array<string, mixed>> $attributes
     * @return array{remote: int, matched: int, created: int, failed: int}
     */
    public static function alignLiveAttributes(ConstituentStore $store, array $attributes): array
    {
        $defs = $store->fieldDefinitions();
        $labels = [];
        $keys = [];
        foreach ($defs as $def) {
            $labels[self::comparable((string) $def->label)] = true;
            $keys[(string) $def->field_key] = true;
        }
        // Names used by LGL for the ten fields BosqueGrande stores
        // natively. They are connector mappings, not duplicate custom
        // definitions (Background Info is BG's Notes field).
        foreach ([
            'First Name', 'Last Name', 'Email', 'Email Address',
            'Phone', 'Phone Number', 'Address', 'Address 1',
            'Street', 'Street Address', 'Address 2', 'City',
            'State', 'Province', 'Postal', 'Postal Code', 'ZIP',
            'ZIP Code', 'Notes', 'Background Info',
        ] as $nativeLabel) {
            $labels[self::comparable($nativeLabel)] = true;
        }

        $result = ['remote' => 0, 'matched' => 0, 'created' => 0, 'failed' => 0];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) continue;
            $name = trim((string) ($attribute['name'] ?? ''));
            if ($name === '') continue;
            $result['remote']++;

            $remoteKey = sanitize_key((string) ($attribute['key'] ?? ''));
            $comparable = self::comparable($name);
            if (($comparable !== '' && isset($labels[$comparable]))
                || ($remoteKey !== '' && isset($keys[$remoteKey]))) {
                $result['matched']++;
                continue;
            }

            $base = (string) ($attribute['key'] ?? $name);
            $base = str_replace(['-', ' '], '_', strtolower(remove_accents($base)));
            $key = substr(sanitize_key($base), 0, 48);
            if ($key === '') {
                $key = 'lgl_field_' . max(0, (int) ($attribute['id'] ?? 0));
            }
            if (isset($keys[$key])) {
                $stem = $key;
                $counter = max(1, (int) ($attribute['id'] ?? 0));
                do {
                    $suffix = '_lgl_' . $counter++;
                    $key = substr($stem, 0, max(1, 48 - strlen($suffix))) . $suffix;
                } while (isset($keys[$key]));
            }

            $id = $store->ensureField($key, $name, true);
            if ($id > 0) {
                $result['created']++;
                $keys[$key] = true;
                if ($comparable !== '') $labels[$comparable] = true;
            } else {
                $result['failed']++;
            }
        }
        return $result;
    }

    private static function comparable(string $value): string
    {
        $value = strtolower(remove_accents($value));
        return (string) preg_replace('/[^a-z0-9]+/', '', $value);
    }

}
