<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * Single bosquegrande_settings option array. Secret keys are encrypted with a
 * purpose-specific context and remain write-only in the admin UI. Values
 * written under an older key are rewrapped under the active key on read.
 */
final class Settings
{
    private const OPTION = 'bosquegrande_settings';

    /** Keys stored encrypted and never redisplayed. */
    private const SECRET_KEYS = [
        'lgl_api_key',
        'stripe_secret_key',
        'stripe_webhook_secret',
        'smtp_password',
        'eventbrite_token',
        'anthropic_api_key',
        'openai_api_key',
        'gemini_api_key',
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;
    private int $cacheBlog = 0;

    public function __construct(private Encryption $encryption)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->load();
        if (!array_key_exists($key, $all)) return $default;
        $value = $all[$key];
        if (in_array($key, self::SECRET_KEYS, true) && is_string($value)) {
            $plain = $this->encryption->decryptOrNull($value, $this->context($key));
            if ($plain === null) return $default;
            if ($plain !== '' && $this->encryption->needsReencrypt($value)) {
                $rewrapped = $this->encryption->encrypt($plain, $this->context($key));
                if ($rewrapped !== '') {
                    $all[$key] = $rewrapped;
                    if (update_option(self::OPTION, $all, false)
                        || get_option(self::OPTION, []) === $all
                    ) {
                        $this->cache = $all;
                    }
                }
            }
            return $plain;
        }
        return $value;
    }

    public function set(string $key, mixed $value): bool
    {
        $all = $this->load();
        if (in_array($key, self::SECRET_KEYS, true) && is_string($value) && $value !== '') {
            if (!$this->encryption->available()) return false;
            $encrypted = $this->encryption->encrypt($value, $this->context($key));
            if ($encrypted === '' || $encrypted === $value) return false;
            $value = $encrypted;
        }
        $all[$key] = $value;
        $this->cache = $all;
        if (update_option(self::OPTION, $all, false)) return true;
        return get_option(self::OPTION, []) === $all;
    }

    public function hasSecret(string $key): bool
    {
        $all = $this->load();
        return in_array($key, self::SECRET_KEYS, true)
            && isset($all[$key])
            && $all[$key] !== '';
    }

    /**
     * True when a secret is on file but can no longer be read (the keyring
     * changed, for example after a site move). Recovery is re-entering the
     * secret; detecting it lets the admin screens say so plainly.
     */
    public function secretUnreadable(string $key): bool
    {
        if (!$this->hasSecret($key)) return false;
        $all = $this->load();
        $value = (string) ($all[$key] ?? '');
        return $this->encryption->decryptOrNull($value, $this->context($key)) === null;
    }

    /**
     * Rewrap every readable credential under the active key. Used by the
     * automated migration/rotation worker; failures are counted and left
     * untouched for recovery.
     *
     * @return array{migrated: int, failed: int}
     */
    public function rewrapSecrets(): array
    {
        $all = $this->load();
        $migrated = 0;
        $failed = 0;
        foreach (self::SECRET_KEYS as $key) {
            $stored = $all[$key] ?? '';
            if (!is_string($stored) || $stored === '' || !$this->encryption->needsReencrypt($stored)) continue;
            $plain = $this->encryption->decryptOrNull($stored, $this->context($key));
            if ($plain === null) {
                $failed++;
                continue;
            }
            $rewrapped = $this->encryption->encrypt($plain, $this->context($key));
            if ($rewrapped === '') {
                $failed++;
                continue;
            }
            $all[$key] = $rewrapped;
            $migrated++;
        }
        if ($migrated > 0) {
            if (!update_option(self::OPTION, $all, false)
                && get_option(self::OPTION, []) !== $all
            ) {
                return ['migrated' => 0, 'failed' => $failed + $migrated];
            }
            $this->cache = $all;
        }
        return ['migrated' => $migrated, 'failed' => $failed];
    }

    /** Drop the per-request cache after another writer changed the option. */
    public function refresh(): void
    {
        $this->cache = null;
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        // On multisite the cache belongs to one site; switching sites reloads.
        $blog = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        if ($this->cache !== null && $this->cacheBlog !== $blog) $this->cache = null;
        $this->cacheBlog = $blog;
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $this->cache = is_array($stored) ? $stored : [];
        }
        return $this->cache;
    }

    private function context(string $key): string
    {
        return 'setting:' . sanitize_key($key);
    }
}
