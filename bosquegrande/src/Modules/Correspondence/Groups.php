<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Correspondence;

use BosqueGrande\Modules\Constituents\ActivityLog;
use BosqueGrande\Modules\Constituents\ConstituentStore;

if (!defined('ABSPATH')) exit;

/**
 * Newsletter groups and the subscription links between people and
 * groups. Subscriptions never delete: status flips between active and
 * unsubscribed so the history stays accurate, and every change lands on
 * the constituent's timeline with a consent row (the double-opt-in
 * evidence trail).
 */
final class Groups
{
    public function __construct(private ConstituentStore $store, private ActivityLog $activity)
    {
    }

    public function create(string $slug, string $name, string $description = ''): int
    {
        global $wpdb;
        $slug = sanitize_key(bg_substr($slug, 0, 48));
        if ($slug === '' || $name === '') return 0;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_groups WHERE slug = %s", $slug
        ));
        if ($exists) return 0;
        $ok = $wpdb->insert($wpdb->prefix . 'bg_groups', [
            'slug' => $slug,
            'name' => bg_substr($name, 0, 190),
            'description' => bg_substr($description, 0, 500),
            'created_at' => current_time('mysql', true),
        ]);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public function bySlug(string $slug, bool $includeArchived = false): ?object
    {
        global $wpdb;
        $archived = $includeArchived ? '' : ' AND archived_at IS NULL';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_groups WHERE slug = %s{$archived}", sanitize_key($slug)
        ));
        return $row ?: null;
    }

    public function byId(int $id, bool $includeArchived = false): ?object
    {
        global $wpdb;
        $archived = $includeArchived ? '' : ' AND archived_at IS NULL';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_groups WHERE id = %d{$archived}", $id
        ));
        return $row ?: null;
    }

    /** @return list<object> With active_count attached. */
    public function all(bool $includeArchived = false): array
    {
        global $wpdb;
        $where = $includeArchived ? '' : ' WHERE g.archived_at IS NULL';
        $groups = $wpdb->get_results(
            "SELECT g.*, COALESCE(c.active_count, 0) AS active_count
             FROM {$wpdb->prefix}bg_groups g
             LEFT JOIN (
                SELECT group_id, COUNT(*) AS active_count
                FROM {$wpdb->prefix}bg_subscriptions
                WHERE status = 'active'
                GROUP BY group_id
             ) c ON c.group_id = g.id{$where}
             ORDER BY g.name ASC"
        ) ?: [];
        foreach ($groups as $group) {
            $group->active_count = (int) $group->active_count;
        }
        return $groups;
    }

    /**
     * Subscribe a constituent to a group. $source records how the
     * consent arrived (confirm-link, admin, import).
     */
    public function subscribe(int $constituentId, int $groupId, string $source): bool
    {
        global $wpdb;
        $group = $this->byId($groupId);
        if (!$group || !$constituentId) return false;
        $t = $wpdb->prefix . 'bg_subscriptions';
        $now = current_time('mysql', true);
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$t} WHERE group_id = %d AND constituent_id = %d",
            $groupId, $constituentId
        ));
        if ($existing && $existing->status === 'active') return true;
        if (!$this->store->beginAtomic()) return false;
        if ($existing) {
            $ok = $wpdb->update(
                $t,
                ['status' => 'active', 'source' => bg_substr($source, 0, 48), 'updated_at' => $now],
                ['id' => (int) $existing->id]
            ) !== false;
        } else {
            $ok = (bool) $wpdb->insert($t, [
                'group_id' => $groupId,
                'constituent_id' => $constituentId,
                'status' => 'active',
                'source' => bg_substr($source, 0, 48),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        if (!$ok
            || !$this->store->recordConsent($constituentId, 'newsletter_' . $group->slug, true, $source)
            || !$this->activity->append($constituentId, 'subscription', 'Subscribed: ' . $group->name)
        ) {
            $this->store->rollbackAtomic();
            return false;
        }
        return $this->store->commitAtomic();
    }

    /** Current subscription status for one person in one group, or null when no row exists. */
    public function subscriptionStatus(int $constituentId, int $groupId): ?string
    {
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}bg_subscriptions WHERE group_id = %d AND constituent_id = %d",
            $groupId, $constituentId
        ));
        return $status === null ? null : (string) $status;
    }

    /**
     * Active selections from the live newsletter catalog for one person.
     * Archived groups remain in consent history but are deliberately absent
     * from public choice forms.
     *
     * @return list<int>
     */
    public function activeIdsForConstituent(int $constituentId): array
    {
        global $wpdb;
        if ($constituentId < 1) return [];
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT s.group_id
             FROM {$wpdb->prefix}bg_subscriptions s
             JOIN {$wpdb->prefix}bg_groups g ON g.id = s.group_id
             WHERE s.constituent_id = %d
               AND s.status = 'active'
               AND g.archived_at IS NULL
             ORDER BY g.name ASC",
            $constituentId
        ));
        return array_values(array_unique(array_map('intval', $ids ?: [])));
    }

    /**
     * Apply a public form's selections against the active Group catalog.
     * Group rows are the only source of newsletter existence: creating a
     * group adds the choice everywhere and archiving it removes the choice
     * everywhere. Unchecked choices become opt-outs only for a caller that
     * has independently confirmed the person's identity.
     *
     * @param array<int, mixed> $selectedIds
     */
    public function saveActiveSelections(
        int $constituentId,
        array $selectedIds,
        string $source,
        bool $allowUnsubscribe,
        bool $allowSubscribe = true
    ): bool {
        if ($constituentId < 1) return false;

        $selected = array_fill_keys($this->validActiveSelectionIds($selectedIds), true);
        $active = array_fill_keys($this->activeIdsForConstituent($constituentId), true);

        // Honor opt-outs first. If a later opt-in write fails, the safer
        // unsubscribe decision has still won.
        if ($allowUnsubscribe) {
            foreach ($active as $groupId => $_active) {
                if (!isset($selected[$groupId])
                    && !$this->unsubscribe($constituentId, (int) $groupId, $source)
                ) return false;
            }
        }
        if ($allowSubscribe) {
            foreach ($selected as $groupId => $_selected) {
                if (!isset($active[$groupId])
                    && !$this->subscribe($constituentId, (int) $groupId, $source)
                ) return false;
            }
        }
        return true;
    }

    /**
     * Sanitize requested ids through the same live Group catalog rendered by
     * public forms. Forged and archived ids never reach a preference write.
     *
     * @param array<int, mixed> $selectedIds
     * @return list<int>
     */
    public function validActiveSelectionIds(array $selectedIds): array
    {
        $catalog = [];
        foreach ($this->all() as $group) $catalog[(int) $group->id] = true;
        $valid = [];
        foreach ($selectedIds as $rawId) {
            $groupId = (int) $rawId;
            if ($groupId > 0 && isset($catalog[$groupId])) $valid[$groupId] = true;
        }
        return array_map('intval', array_keys($valid));
    }

    public function unsubscribe(int $constituentId, int $groupId, string $source): bool
    {
        global $wpdb;
        // Sent mail remains actionable after staff archive its audience.
        $group = $this->byId($groupId, true);
        if (!$group) return false;
        $current = $this->subscriptionStatus($constituentId, $groupId);
        if ($current === 'unsubscribed') {
            // A repeated click is still a fresh opt-out decision. Refresh the
            // ordering timestamp so a confirmation link issued before this
            // click cannot later reactivate the subscription.
            return $wpdb->update(
                $wpdb->prefix . 'bg_subscriptions',
                ['updated_at' => current_time('mysql', true)],
                ['group_id' => $groupId, 'constituent_id' => $constituentId]
            ) !== false;
        }
        if ($current === null) return false;
        if (!$this->store->beginAtomic()) return false;
        $updated = $wpdb->update($wpdb->prefix . 'bg_subscriptions',
            ['status' => 'unsubscribed', 'updated_at' => current_time('mysql', true)],
            ['group_id' => $groupId, 'constituent_id' => $constituentId]);
        if (!$updated
            || !$this->store->recordConsent($constituentId, 'newsletter_' . $group->slug, false, $source)
            || !$this->activity->append($constituentId, 'subscription', 'Unsubscribed: ' . $group->name)
        ) {
            $this->store->rollbackAtomic();
            return false;
        }
        return $this->store->commitAtomic();
    }

    /**
     * Archive a newsletter audience so it disappears from compose and My
     * Profile without erasing anyone's consent history. The deployment's
     * Monthly Newsletter is permanent because it carries the legacy
     * MailChimp Status contract.
     */
    public function archive(int $groupId): bool
    {
        global $wpdb;
        $group = $this->byId($groupId, true);
        if (!$group || (string) $group->slug === 'general') return false;
        if (!empty($group->archived_at)) return true;
        return $wpdb->update(
            $wpdb->prefix . 'bg_groups',
            ['archived_at' => current_time('mysql', true)],
            ['id' => $groupId]
        ) !== false;
    }

    /** @return list<int> Active constituent ids for a group, deduplicated. */
    public function activeConstituentIds(int $groupId): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT constituent_id FROM {$wpdb->prefix}bg_subscriptions
             WHERE group_id = %d AND status = 'active' ORDER BY constituent_id ASC",
            $groupId
        ));
        return array_map('intval', $ids ?: []);
    }

    /** @return list<object> A constituent's subscriptions with group names. */
    public function forConstituent(int $constituentId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.status, s.source, s.updated_at, g.name, g.slug, g.id AS group_id
             FROM {$wpdb->prefix}bg_subscriptions s
             JOIN {$wpdb->prefix}bg_groups g ON g.id = s.group_id
             WHERE s.constituent_id = %d ORDER BY g.name ASC",
            $constituentId
        )) ?: [];
    }

    /* ── Global suppression (above every group) ───────────────────── */

    public function suppress(string $emailBidx, string $reason): bool
    {
        global $wpdb;
        if ($emailBidx === '') return false;
        $t = $wpdb->prefix . 'bg_suppression';
        $reason = bg_substr($reason, 0, 48);
        if ($reason === '') return false;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, reason FROM {$t} WHERE email_bidx = %s", $emailBidx
        ));
        if ($existing) {
            // Repeating the same suppression is a new preference signal, so
            // refresh its ordering timestamp. A different existing reason is
            // protected: public unsubscribe-all must never replace an admin,
            // complaint, or delivery suppression. The reverse is an allowed
            // safety escalation: a later protected reason replaces the older
            // visitor-owned marker and can no longer be cleared by re-opt-in.
            if ((string) $existing->reason !== $reason) {
                if ((string) $existing->reason === 'unsubscribe-all'
                    && $reason !== 'unsubscribe-all'
                ) {
                    return $wpdb->update(
                        $t,
                        ['reason' => $reason, 'created_at' => current_time('mysql', true)],
                        ['id' => (int) $existing->id, 'reason' => 'unsubscribe-all']
                    ) !== false;
                }
                return true;
            }
            return $wpdb->update(
                $t,
                ['created_at' => current_time('mysql', true)],
                ['id' => (int) $existing->id, 'reason' => $reason]
            ) !== false;
        }
        return (bool) $wpdb->insert($t, [
            'email_bidx' => $emailBidx,
            'reason' => $reason,
            'created_at' => current_time('mysql', true),
        ]);
    }

    public function suppressionReason(string $emailBidx): ?string
    {
        global $wpdb;
        if ($emailBidx === '') return null;
        $reason = $wpdb->get_var($wpdb->prepare(
            "SELECT reason FROM {$wpdb->prefix}bg_suppression WHERE email_bidx = %s",
            $emailBidx
        ));
        return $reason === null ? null : (string) $reason;
    }

    /**
     * Reverse only the visitor's own global "unsubscribe all" decision.
     * Complaint, administrative, and delivery suppressions are intentionally
     * untouchable from a public preference form.
     */
    public function clearUserUnsubscribeAll(
        string $emailBidx,
        int $constituentId = 0,
        string $source = 'preference'
    ): bool
    {
        global $wpdb;
        if ($emailBidx === '') return false;
        if ($this->suppressionReason($emailBidx) !== 'unsubscribe-all') return true;
        if ($constituentId < 1) {
            // Legacy internal callers may still clear the visitor-owned row;
            // identified public flows supply the constituent so the positive
            // global-delivery consent is recorded as well.
            return $wpdb->delete(
                $wpdb->prefix . 'bg_suppression',
                ['email_bidx' => $emailBidx, 'reason' => 'unsubscribe-all']
            ) !== false;
        }
        if (!$this->store->beginAtomic()) return false;
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'bg_suppression',
            ['email_bidx' => $emailBidx, 'reason' => 'unsubscribe-all']
        );
        if ($deleted !== 1
            || !$this->store->recordConsent(
                $constituentId,
                'newsletter_delivery',
                true,
                $source
            )
        ) {
            $this->store->rollbackAtomic();
            return false;
        }
        // recordConsent appends the matching consent activity inside the same
        // nest-safe transaction; the suppression removal and audit evidence
        // therefore either both commit or neither does.
        return $this->store->commitAtomic();
    }

    /**
     * Consume a signed double-opt-in choice without allowing an older link to
     * overrule a newer opt-out. The subscription and suppression rows are
     * locked together so an unsubscribe racing this request always wins in
     * real-time order.
     *
     * @return 'confirmed'|'stale'|'restricted'|'failed'
     */
    public function confirmIssuedSelection(
        int $constituentId,
        int $groupId,
        string $emailBidx,
        int $issuedAt,
        string $source = 'confirm-link'
    ): string {
        global $wpdb;
        if ($constituentId < 1 || $groupId < 1 || $emailBidx === '' || $issuedAt < 1) {
            return 'stale';
        }
        if (!$this->byId($groupId) || !$this->store->beginAtomic()) return 'failed';

        $suppression = $wpdb->get_row($wpdb->prepare(
            "SELECT reason, created_at
             FROM {$wpdb->prefix}bg_suppression
             WHERE email_bidx = %s
             FOR UPDATE",
            $emailBidx
        ));
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT status, updated_at
             FROM {$wpdb->prefix}bg_subscriptions
             WHERE group_id = %d AND constituent_id = %d
             FOR UPDATE",
            $groupId,
            $constituentId
        ));

        if ($suppression && (string) $suppression->reason !== 'unsubscribe-all') {
            $this->store->rollbackAtomic();
            return 'restricted';
        }
        if ($suppression
            && $this->utcMysqlTimestamp((string) $suppression->created_at) >= $issuedAt
        ) {
            $this->store->rollbackAtomic();
            return 'stale';
        }
        if ($subscription
            && (string) $subscription->status === 'unsubscribed'
            && $this->utcMysqlTimestamp((string) $subscription->updated_at) >= $issuedAt
        ) {
            $this->store->rollbackAtomic();
            return 'stale';
        }

        if (!$this->subscribe($constituentId, $groupId, $source)
            || !$this->clearUserUnsubscribeAll($emailBidx, $constituentId, $source)
        ) {
            $this->store->rollbackAtomic();
            return 'failed';
        }
        return $this->store->commitAtomic() ? 'confirmed' : 'failed';
    }

    /** MySQL UTC DATETIME (written with current_time(..., true)) to epoch. */
    private function utcMysqlTimestamp(string $value): int
    {
        if ($value === '') return 0;
        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? 0 : $timestamp;
    }

    public function suppressed(string $emailBidx): bool
    {
        global $wpdb;
        if ($emailBidx === '') return false;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_suppression WHERE email_bidx = %s", $emailBidx
        )) !== null;
    }
}
