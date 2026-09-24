<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * A small, file-backed installation keyring.
 *
 * One protected PHP file is written atomically under wp-content, or wherever
 * BG_KEYRING_PATH or the bg_keyring_path filter points (the hosted edition
 * uses the filter to give each site its own file). Every key is random. Key
 * material never lives in a WordPress option or table.
 *
 * The ring holds: data keys by id (the active one encrypts, older ones stay
 * for reading until re-encryption finishes), a blind-index key that never
 * rotates, and a signing key for tokens and links.
 *
 * When the bg_keyring_seal_key filter supplies a 32-byte key (the hosted
 * edition derives one per site from a master key held outside WordPress),
 * the file is sealed with XChaCha20-Poly1305 and is read as data, never
 * executed. An existing unsealed ring is resealed on first use.
 */
final class KeyStore
{
    public const FILE = 'bosquegrande-keyring.php';
    private const FORMAT = 1;
    private const KEY_BYTES = 32;
    private const SEALED_HEADER = "<?php exit; ?>\nBGSEALED1\n";
    private const SEAL_AAD = 'bosquegrande-keyring|sealed|1';

    /** @var array<string, mixed>|null */
    private ?array $cache = null;
    private string $cachePath = '';
    private string $status = 'unchecked';

    /** An injectable path keeps the crypto layer testable; production uses the default. */
    public function __construct(private ?string $path = null)
    {
    }

    public function path(): string
    {
        if ($this->path !== null) return $this->path;
        if (defined('BG_KEYRING_PATH') && is_string(BG_KEYRING_PATH) && BG_KEYRING_PATH !== '') {
            $path = BG_KEYRING_PATH;
        } else {
            $content = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : dirname(BG_DIR, 2);
            $path = rtrim($content, '/\\') . '/' . self::FILE;
        }
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('bg_keyring_path', $path);
            if (is_string($filtered) && $filtered !== '') $path = $filtered;
        }
        return $path;
    }

    public function ensure(): bool
    {
        $path = $this->path();
        // The cache belongs to one file. A multisite request that switches
        // sites must never encrypt with another site's keys.
        if ($this->cache !== null && $this->cachePath === $path) return true;
        $this->cache = null;
        $this->cachePath = $path;

        if ($this->sealKey() === null) {
            $this->status = 'invalid-seal-key';
            return false;
        }
        if (is_file($path)) {
            $loaded = $this->readRing($path);
            if ($loaded !== null) {
                $this->cache = $loaded;
                $this->status = 'ready-file';
                $this->resealIfNeeded($path, $loaded);
                return true;
            }
            // Never overwrite an invalid key file: it may be the only copy of
            // key material needed to recover an existing database.
            $this->status = 'invalid-file';
            return false;
        }

        // Without a writable location the site fails closed: nothing
        // sensitive is written until a keyring exists.
        return $this->createFileRing($path);
    }

    public function status(): string
    {
        $this->ensure();
        return $this->status;
    }

    public function activeId(): string
    {
        $ring = $this->ring();
        return is_array($ring) ? (string) ($ring['active'] ?? '') : '';
    }

    public function activeKey(): string
    {
        $ring = $this->ring();
        if (!is_array($ring)) return '';
        return $this->decodeKey((string) ($ring['keys'][$ring['active']] ?? ''));
    }

    public function key(string $id): string
    {
        $ring = $this->ring();
        if (!is_array($ring)) return '';
        return $this->decodeKey((string) ($ring['keys'][$id] ?? ''));
    }

    /** @return array<string, string> key id => raw key */
    public function keys(): array
    {
        $ring = $this->ring();
        if (!is_array($ring)) return [];
        $out = [];
        foreach ((array) ($ring['keys'] ?? []) as $id => $encoded) {
            $decoded = $this->decodeKey((string) $encoded);
            if ($decoded !== '') $out[(string) $id] = $decoded;
        }
        return $out;
    }

    public function indexKey(): string
    {
        $ring = $this->ring();
        return is_array($ring) ? $this->decodeKey((string) ($ring['index_key'] ?? '')) : '';
    }

    /**
     * Key for one signing purpose (session cookies, email links, form
     * bindings). Rings created before v5 have no signing key; theirs is
     * derived from the index key, which is equally private to the site.
     */
    public function signingKey(string $purpose): string
    {
        $ring = $this->ring();
        if (!is_array($ring)) return '';
        $root = $this->decodeKey((string) ($ring['signing_key'] ?? ''));
        if ($root === '') {
            $index = $this->indexKey();
            if ($index === '') return '';
            $root = hash_hmac('sha256', 'bosquegrande-signing-root', $index, true);
        }
        // On a network, sites may share one keyring file. Binding the purpose
        // to the site keeps a link signed for one site from working on another.
        $scope = function_exists('is_multisite') && is_multisite() ? '|site-' . (int) get_current_blog_id() : '';
        return hash_hmac('sha256', 'bosquegrande-sign|' . $purpose . $scope, $root, true);
    }

    /**
     * Add a new active data key while retaining every earlier key for
     * uninterrupted reads. The migration worker re-encrypts records in the
     * background; retained keys make interruption and rollback harmless.
     */
    public function rotate(): ?string
    {
        $ring = $this->ring();
        if (!is_array($ring)) return null;
        $lock = $this->openLock();
        if (!is_resource($lock)) return null;
        try {
            // Reload inside the lock so simultaneous administrator requests
            // cannot discard one another's retained keys.
            if (is_file($this->path())) {
                $disk = $this->readRing($this->path());
                if ($disk === null) return null;
                $ring = $disk;
            }
            $id = $this->newKeyId();
            $ring['keys'][$id] = base64_encode(random_bytes(self::KEY_BYTES));
            $ring['active'] = $id;
            $ring['rotated_at'] = gmdate('c');
            if (!$this->writeRing($ring)) return null;
            $this->cache = $ring;
            $this->cachePath = $this->path();
            $this->status = 'ready-file';
            return $id;
        } catch (\Throwable) {
            return null;
        } finally {
            $this->closeLock($lock);
        }
    }

    /** A recovery document suitable for an administrator-only download. */
    public function exportJson(): string
    {
        $ring = $this->ring();
        if (!is_array($ring)) return '';
        $payload = [
            'product' => 'BosqueGrande',
            'format' => 'keyring-backup-v1',
            'exported_at' => gmdate('c'),
            'keyring' => $ring,
        ];
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json . "\n" : '';
    }

    /** @return array<string, mixed>|null */
    private function ring(): ?array
    {
        return $this->ensure() ? $this->cache : null;
    }

    /** @return array<string, mixed> */
    private function newRing(): array
    {
        $id = $this->newKeyId();
        return [
            'format' => self::FORMAT,
            'active' => $id,
            'index_key' => base64_encode(random_bytes(self::KEY_BYTES)),
            'signing_key' => base64_encode(random_bytes(self::KEY_BYTES)),
            'keys' => [$id => base64_encode(random_bytes(self::KEY_BYTES))],
            'created_at' => gmdate('c'),
        ];
    }

    private function newKeyId(): string
    {
        return 'k' . gmdate('Ymd') . '-' . bin2hex(random_bytes(4));
    }

    /** @return array<string, mixed>|null */
    private function readRing(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') return null;
        if (str_starts_with($raw, self::SEALED_HEADER)) {
            $ring = $this->unseal(substr($raw, strlen(self::SEALED_HEADER)));
        } else {
            try {
                $ring = include $path;
            } catch (\Throwable) {
                return null;
            }
        }
        return is_array($ring) && $this->valid($ring) ? $ring : null;
    }

    /**
     * The seal key from the bg_keyring_seal_key filter: '' when none is
     * configured, null when one is configured but malformed (fail closed).
     */
    private function sealKey(): ?string
    {
        if (!function_exists('apply_filters')) return '';
        $raw = apply_filters('bg_keyring_seal_key', '');
        if (!is_string($raw) || $raw === '') return '';
        if (strlen($raw) === self::KEY_BYTES) return $raw;
        $decoded = base64_decode($raw, true);
        return is_string($decoded) && strlen($decoded) === self::KEY_BYTES ? $decoded : null;
    }

    /** @return array<string, mixed>|null */
    private function unseal(string $body): ?array
    {
        $key = $this->sealKey();
        if ($key === null || $key === '') return null;
        $blob = base64_decode(trim($body), true);
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (!is_string($blob) || strlen($blob) <= $nonceBytes) return null;
        try {
            $json = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($blob, $nonceBytes), self::SEAL_AAD, substr($blob, 0, $nonceBytes), $key
            );
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($json)) return null;
        $ring = json_decode($json, true);
        return is_array($ring) ? $ring : null;
    }

    /** @param array<string, mixed> $ring */
    private function resealIfNeeded(string $path, array $ring): void
    {
        if ($this->sealKey() === '') return;
        $head = (string) @file_get_contents($path, false, null, 0, strlen(self::SEALED_HEADER));
        if ($head === self::SEALED_HEADER) return;
        $lock = $this->openLock();
        if (!is_resource($lock)) return;
        try {
            $this->writeRing($ring);
        } finally {
            $this->closeLock($lock);
        }
    }

    private function createFileRing(string $path): bool
    {
        $lock = $this->openLock();
        if (!is_resource($lock)) {
            $this->status = 'unwritable';
            return false;
        }
        try {
            // Another request may have won the race while this one waited.
            clearstatcache(true, $path);
            if (is_file($path)) {
                $loaded = $this->readRing($path);
                if ($loaded === null) {
                    $this->status = 'invalid-file';
                    return false;
                }
                $this->cache = $loaded;
                $this->cachePath = $path;
                $this->status = 'ready-file';
                return true;
            }
            try {
                $ring = $this->newRing();
            } catch (\Throwable) {
                $this->status = 'generation-failed';
                return false;
            }
            if (!$this->writeRing($ring)) {
                $this->status = 'unwritable';
                return false;
            }
            $this->cache = $ring;
            $this->cachePath = $path;
            $this->status = 'ready-file';
            return true;
        } finally {
            $this->closeLock($lock);
        }
    }

    /** @return resource|false */
    private function openLock()
    {
        $dir = dirname($this->path());
        if (!is_dir($dir) || !is_writable($dir)) return false;
        $lock = @fopen($this->path() . '.lock', 'c');
        if (!is_resource($lock)) return false;
        @chmod($this->path() . '.lock', 0600);
        if (!@flock($lock, LOCK_EX)) {
            fclose($lock);
            return false;
        }
        return $lock;
    }

    /** @param resource $lock */
    private function closeLock($lock): void
    {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }

    /** @param array<string, mixed> $ring */
    private function valid(array $ring): bool
    {
        if ((int) ($ring['format'] ?? 0) !== self::FORMAT) return false;
        $active = (string) ($ring['active'] ?? '');
        if ($active === '' || !isset($ring['keys'][$active])) return false;
        if ($this->decodeKey((string) ($ring['index_key'] ?? '')) === '') return false;
        foreach ((array) ($ring['keys'] ?? []) as $id => $encoded) {
            if (!is_string($id) || $id === '' || $this->decodeKey((string) $encoded) === '') return false;
        }
        if (isset($ring['signing_key']) && $this->decodeKey((string) $ring['signing_key']) === '') return false;
        return true;
    }

    /** @param array<string, mixed> $ring */
    private function writeRing(array $ring): bool
    {
        if (!$this->valid($ring)) return false;
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) return false;
        try {
            $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        } catch (\Throwable) {
            return false;
        }
        $seal = $this->sealKey();
        if ($seal === null) return false;
        if ($seal !== '') {
            $json = wp_json_encode($ring);
            if (!is_string($json)) return false;
            try {
                $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            } catch (\Throwable) {
                return false;
            }
            $content = self::SEALED_HEADER . base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $json, self::SEAL_AAD, $nonce, $seal
            )) . "\n";
        } else {
            $content = "<?php\n"
                . "if (!defined('ABSPATH')) exit;\n"
                . "// BosqueGrande keyring. Back this file up; never publish or email it.\n"
                . 'return ' . var_export($ring, true) . ";\n";
        }
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        $written = $this->readRing($tmp);
        if ($written === null || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0600);
        return true;
    }

    private function decodeKey(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);
        return is_string($decoded) && strlen($decoded) === self::KEY_BYTES ? $decoded : '';
    }
}
