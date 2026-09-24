<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * Authenticated encryption for stored personal data.
 *
 * Each value uses XChaCha20-Poly1305 with a fresh 192-bit nonce. Additional
 * authenticated data binds ciphertext to its storage purpose (and, where
 * available, its record id), preventing a valid encrypted value from being
 * copied into a different field or constituent. Equality matching uses a
 * separate HMAC key and never decrypts the table during login/prefill.
 * Anything that is not bg2 ciphertext is rejected on read.
 */
final class Encryption
{
    private const PREFIX = 'bg2:';

    public function __construct(private KeyStore $keys)
    {
    }

    public function available(): bool
    {
        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
            && $this->keys->ensure()
            && $this->keys->activeKey() !== ''
            && $this->keys->indexKey() !== '';
    }

    public function activeKeyId(): string
    {
        return $this->available() ? $this->keys->activeId() : '';
    }

    public function keyStatus(): string
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            return 'libsodium-missing';
        }
        return $this->keys->status();
    }

    public function blindIndex(string $normalized): string
    {
        if ($normalized === '' || !$this->available()) return '';
        return hash_hmac('sha256', $normalized, $this->keys->indexKey());
    }

    public function indexFingerprint(): string
    {
        $key = $this->keys->indexKey();
        return $key === '' ? '' : hash('sha256', 'bosquegrande-bidx-fp|' . $key);
    }

    public function encrypt(string $plaintext, string $context = 'generic'): string
    {
        if ($plaintext === '') return '';
        if (!$this->available()) return '';
        try {
            $id = $this->keys->activeId();
            $key = $this->keys->activeKey();
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $this->aad($context, $id),
                $nonce,
                $key
            );
            return self::PREFIX . $id . ':' . base64_encode($nonce . $cipher);
        } catch (\Throwable) {
            return '';
        }
    }

    public function decrypt(string $stored, string $context = 'generic'): string
    {
        return $this->decryptOrNull($stored, $context) ?? '';
    }

    /**
     * Null means authentication/decryption failure; an empty string is a
     * legitimate stored value.
     */
    public function decryptOrNull(string $stored, string $context = 'generic'): ?string
    {
        if ($stored === '') return '';
        if (str_starts_with($stored, self::PREFIX)) {
            if (!$this->available()) return null;
            $parts = explode(':', $stored, 3);
            if (count($parts) !== 3 || $parts[1] === '' || $parts[2] === '') return null;
            $id = $parts[1];
            $key = $this->keys->key($id);
            if ($key === '') return null;
            $raw = base64_decode($parts[2], true);
            $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if (!is_string($raw) || strlen($raw) <= $nonceBytes) return null;
            try {
                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    substr($raw, $nonceBytes),
                    $this->aad($context, $id),
                    substr($raw, 0, $nonceBytes),
                    $key
                );
                return is_string($plain) ? $plain : null;
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    public function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /** Per-site HMAC key for one purpose; empty when the keyring is unavailable. */
    public function signingKey(string $purpose): string
    {
        return $this->available() ? $this->keys->signingKey($purpose) : '';
    }

    public function needsReencrypt(string $stored): bool
    {
        $id = $this->activeKeyId();
        return $stored !== '' && ($id === '' || !str_starts_with($stored, self::PREFIX . $id . ':'));
    }

    /** @return array{ok: bool, key_id: string, status: string} */
    public function healthCheck(): array
    {
        $status = $this->keyStatus();
        if (!$this->available()) {
            return ['ok' => false, 'key_id' => '', 'status' => $status];
        }
        $probe = 'bg-health-' . bin2hex(random_bytes(8));
        $cipher = $this->encrypt($probe, 'health-check');
        $ok = $cipher !== '' && hash_equals($probe, $this->decrypt($cipher, 'health-check'));
        return ['ok' => $ok, 'key_id' => $this->activeKeyId(), 'status' => $ok ? $status : 'self-test-failed'];
    }

    private function aad(string $context, string $id): string
    {
        $context = preg_replace('/[^A-Za-z0-9:_.-]/', '_', $context) ?: 'generic';
        return 'BosqueGrande|bg2|' . $id . '|' . $context;
    }
}
