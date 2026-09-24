<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Identity;

if (!defined('ABSPATH')) exit;

/**
 * Persistence for identity tokens (table: bg_identity_tokens, M002).
 * Two families:
 *   magic / magic_bg : single-use, expiring verification links for an
 *                      LGL or BosqueGrande constituent respectively.
 *   tier1            : durable LGL newsletter-link token, revocable.
 *
 * The historic lgl_constituent_id column stores the source-specific id
 * for magic tokens. The token kind tells us how to interpret it. This
 * keeps existing installations upgrade-safe without a schema rewrite.
 * Only SHA-256 hashes are stored; raw tokens exist only in links.
 */
final class TokenRepository
{
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bg_identity_tokens';
    }

    /**
     * On-demand sign-in links stay short-lived (15 minutes). Links baked
     * into confirmation emails get a longer window ($ttl), since people
     * open those hours later; still single-use and inbox-bound.
     */
    public function createMagic(
        string $email,
        ?int $constituentId,
        int $ttl = 15 * MINUTE_IN_SECONDS,
        string $source = 'lgl'
    ): string {
        $raw = wp_generate_password(40, false);
        $kind = $source === 'bg' ? 'magic_bg' : 'magic';
        global $wpdb;
        $ok = $wpdb->insert($this->table(), [
            'kind' => $kind,
            'token_hash' => hash('sha256', $raw),
            'email' => strtolower(trim($email)),
            'lgl_constituent_id' => $constituentId,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + max(60, $ttl)),
            'created_at' => current_time('mysql', true),
        ]);
        return $ok ? $raw : '';
    }

    /**
     * Consume a magic token: valid, unexpired, unused. Marks it used
     * atomically so a link cannot verify twice.
     */
    public function consumeMagic(string $raw): ?object
    {
        global $wpdb;
        $hash = hash('sha256', $raw);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE kind IN ('magic', 'magic_bg') AND token_hash = %s AND used_at IS NULL
               AND expires_at > UTC_TIMESTAMP() LIMIT 1",
            $hash
        ));
        if (!$row) return null;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET used_at = UTC_TIMESTAMP()
             WHERE id = %d AND used_at IS NULL",
            $row->id
        ));
        return $updated ? $row : null;
    }

    /** Get or create the durable Tier 1 token for an LGL constituent. */
    public function ensureTier1(int $constituentId): array
    {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE kind = 'tier1' AND lgl_constituent_id = %d AND revoked_at IS NULL LIMIT 1",
            $constituentId
        ));
        if ($existing) {
            return ['created' => false, 'raw' => null, 'row' => $existing];
        }
        $raw = wp_generate_password(32, false);
        $ok = $wpdb->insert($this->table(), [
            'kind' => 'tier1',
            'token_hash' => hash('sha256', $raw),
            'lgl_constituent_id' => $constituentId,
            'created_at' => current_time('mysql', true),
        ]);
        return $ok
            ? ['created' => true, 'raw' => $raw, 'row' => null]
            : ['created' => false, 'raw' => null, 'row' => null];
    }

    /** Resolve a Tier 1 token from a register-link URL to an LGL constituent id. */
    public function resolveTier1(string $raw): ?int
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT lgl_constituent_id FROM {$this->table()}
             WHERE kind = 'tier1' AND token_hash = %s AND revoked_at IS NULL LIMIT 1",
            hash('sha256', $raw)
        ));
        return $id ? (int) $id : null;
    }

    /** Confirm that a raw value currently stored in LGL matches this person's active token. */
    public function matchesTier1(int $constituentId, string $raw): bool
    {
        global $wpdb;
        if ($constituentId < 1 || $raw === '') return false;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()}
             WHERE kind = 'tier1' AND lgl_constituent_id = %d
               AND token_hash = %s AND revoked_at IS NULL",
            $constituentId,
            hash('sha256', $raw)
        )) === 1;
    }

    /** Revoke and reissue (rotation on profile-form use, ADR-3). */
    public function rotateTier1(int $constituentId): string
    {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) return '';
        $revoked = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET revoked_at = UTC_TIMESTAMP()
             WHERE kind = 'tier1' AND lgl_constituent_id = %d AND revoked_at IS NULL",
            $constituentId
        ));
        if ($revoked === false) {
            $wpdb->query('ROLLBACK');
            return '';
        }
        $made = $this->ensureTier1($constituentId);
        $raw = (string) $made['raw'];
        if ($raw === '' || $wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return '';
        }
        return $raw;
    }

    public function revokeTier1(int $constituentId): bool
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET revoked_at = UTC_TIMESTAMP()
             WHERE kind = 'tier1' AND lgl_constituent_id = %d AND revoked_at IS NULL",
            $constituentId
        )) !== false;
    }

    public function purgeExpiredMagic(): int
    {
        global $wpdb;
        return (int) $wpdb->query(
            "DELETE FROM {$this->table()}
             WHERE kind IN ('magic', 'magic_bg')
               AND (expires_at < UTC_TIMESTAMP() OR used_at IS NOT NULL)"
        );
    }
}
