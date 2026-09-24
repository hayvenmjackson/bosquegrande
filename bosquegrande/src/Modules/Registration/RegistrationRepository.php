<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Registration;

if (!defined('ABSPATH')) exit;

/**
 * Rows in bg_registrations (M001). No demographic data is stored on
 * registration rows (ADR-4); person-level answers live separately in
 * the constituent store's encrypted custom values and may also sync
 * to LGL.
 */
final class RegistrationRepository
{
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bg_registrations';
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert($this->table(), [
            'event_post_id' => (int) $data['event_post_id'],
            'lgl_event_id' => $data['lgl_event_id'] ?: null,
            'lgl_constituent_id' => $data['lgl_constituent_id'] ?: null,
            'bg_constituent_id' => $data['bg_constituent_id'] ?: null,
            'tier' => (string) $data['tier'],
            'amount_cents' => (int) $data['amount_cents'],
            'status' => (string) $data['status'],
            'volunteer_interest' => !empty($data['volunteer_interest']) ? 1 : 0,
            'lgl_invitation_id' => $data['lgl_invitation_id'] ?? null,
            'is_new_constituent' => !empty($data['is_new_constituent']) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * Insert a confirmed historical registration without manufacturing a
     * payment, LGL gift, check-in, or current timestamp. The caller must have
     * already deduplicated the external source record.
     * @param array<string,mixed> $data
     */
    public function insertHistorical(array $data, string $registeredAt): int
    {
        global $wpdb;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $registeredAt, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))) {
            return 0;
        }
        $ok = $wpdb->insert($this->table(), [
            'event_post_id' => max(0, (int) ($data['event_post_id'] ?? 0)),
            'lgl_event_id' => null,
            'lgl_constituent_id' => null,
            'bg_constituent_id' => max(0, (int) ($data['bg_constituent_id'] ?? 0)) ?: null,
            'tier' => 'historical',
            'amount_cents' => 0,
            'status' => 'confirmed',
            'volunteer_interest' => 0,
            'lgl_invitation_id' => null,
            'is_new_constituent' => !empty($data['is_new_constituent']) ? 1 : 0,
            'created_at' => $registeredAt,
            'updated_at' => $registeredAt,
        ]);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public function find(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d", $id
        ));
        return $row ?: null;
    }

    public function latestActiveForEventAndConstituent(int $eventId, int $constituentId): ?object
    {
        global $wpdb;
        if ($eventId < 1 || $constituentId < 1) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE event_post_id = %d AND bg_constituent_id = %d
               AND status IN ('pending','processing','confirmed','paid','refund_pending')
             ORDER BY id DESC LIMIT 1",
            $eventId,
            $constituentId
        ));
        return $row ?: null;
    }

    public function finishSettlement(
        int $id,
        string $token,
        string $paymentIntent,
        ?int $invitationId,
        ?int $giftId
    ): bool
    {
        global $wpdb;
        return $wpdb->update($this->table(), [
            'status' => 'paid',
            'stripe_payment_intent' => $paymentIntent,
            'lgl_invitation_id' => $invitationId,
            'lgl_gift_id' => $giftId,
            'settlement_token' => null,
            'settlement_started_at' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $id, 'settlement_token' => $token]) === 1;
    }

    /**
     * Atomically acquire payment settlement. "paid" means another worker
     * already completed it; "busy" means a live worker owns it.
     */
    public function claimSettlement(int $id, string $paymentIntent): string
    {
        global $wpdb;
        $token = wp_generate_uuid4();
        $stale = gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS);
        $sql = $wpdb->prepare(
            "UPDATE {$this->table()}
             SET status = 'processing', settlement_token = %s,
                 settlement_started_at = %s, stripe_payment_intent = %s,
                 updated_at = %s
             WHERE id = %d
               AND (
                    status = 'pending'
                    OR (status = 'processing' AND settlement_started_at < %s)
               )",
            $token,
            current_time('mysql', true),
            $paymentIntent,
            current_time('mysql', true),
            $id,
            $stale
        );
        if ($wpdb->query($sql) === 1) return $token;
        $row = $this->find($id);
        if ($row && $row->status === 'paid') return 'paid';
        if ($row && $row->status === 'processing') return 'busy';
        return '';
    }

    /** Save each remote side effect immediately so a retry can skip it. */
    public function saveSettlementArtifacts(int $id, string $token, ?int $invitationId, ?int $giftId): bool
    {
        global $wpdb;
        $fields = ['updated_at' => current_time('mysql', true)];
        if ($invitationId) $fields['lgl_invitation_id'] = $invitationId;
        if ($giftId) $fields['lgl_gift_id'] = $giftId;
        return $wpdb->update($this->table(), $fields, [
            'id' => $id,
            'settlement_token' => $token,
        ]) !== false;
    }

    public function releaseSettlement(int $id, string $token): void
    {
        global $wpdb;
        $wpdb->update($this->table(), [
            'status' => 'pending',
            'settlement_token' => null,
            'settlement_started_at' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $id, 'settlement_token' => $token]);
    }

    public function setPaymentIntent(int $id, string $paymentIntent): bool
    {
        global $wpdb;
        if ($id < 1 || strpos($paymentIntent, 'pi_') !== 0) return false;
        return $wpdb->update($this->table(), [
            'stripe_payment_intent' => $paymentIntent,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $id]) !== false;
    }

    public function setInvitationId(int $id, int $invitationId): bool
    {
        global $wpdb;
        if ($id < 1 || $invitationId < 1) return false;
        return $wpdb->update($this->table(), [
            'lgl_invitation_id' => $invitationId,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $id]) !== false;
    }

    public function setStatus(int $id, string $status, ?int $constituentId = null): bool
    {
        global $wpdb;
        $fields = ['status' => $status, 'updated_at' => current_time('mysql', true)];
        if ($constituentId) $fields['lgl_constituent_id'] = $constituentId;
        return $wpdb->update($this->table(), $fields, ['id' => $id]) !== false;
    }

    public function countForEvent(int $eventPostId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE event_post_id = %d AND status IN ('confirmed','paid')",
            $eventPostId
        ));
    }
}
