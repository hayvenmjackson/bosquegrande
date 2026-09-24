<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Profile;

use BosqueGrande\Core\Container;
use BosqueGrande\Modules\Constituents\CoreFields;

if (!defined('ABSPATH')) exit;

/**
 * Verified monthly-donation management for a signed-in profile.
 *
 * Financial state moves first: one uniquely matched monthly Stripe
 * subscription must be changed or gone before BosqueGrande or LGL is marked
 * inactive. That ordering prevents a failed processor call from
 * displaying "cancelled" while another charge remains scheduled.
 */
final class MonthlyDonationCancellation
{
    public const RETRY_JOB = 'monthly_donor_lgl_cancel_sync';

    public function __construct(private Container $container)
    {
    }

    /**
     * Human-readable donation-management results. Processor identifiers,
     * verified amounts, record bindings, and state transitions are never read
     * from wording settings.
     *
     * @param array<string, array{label:string,text:string}> $defaults
     * @return array<string, array{label:string,text:string}>
     */
    public static function wordingDefaults(array $defaults): array
    {
        $items = [
            'monthly_manage_invalid_record' => ['Monthly donation management: unresolved-profile message', 'Your verified constituent record could not be resolved.'],
            'monthly_manage_shared_cash' => ['Monthly donation management: shared cash/check email warning', 'This cash/check register email belongs to more than one contact record. Staff must update the exact record ID manually so one household member is not changed for another.'],
            'monthly_manage_shared_unbound_stripe' => ['Monthly donation management: unbound shared-email warning', 'This email belongs to more than one contact record, and the Stripe subscription is not bound to this permanent record ID. For safety, we will not guess which person owns the charge. Please contact us.'],
            'monthly_manage_stripe_found' => ['Monthly donation management: matched-Stripe result', 'Stripe found one monthly donation for this verified account.'],
            'monthly_manage_other_person' => ['Monthly donation management: other-person result', 'The monthly donation using this shared email belongs to a different permanent record ID.'],
            'monthly_manage_ambiguous' => ['Monthly donation management: ambiguous-match warning', 'More than one person or monthly charge may use this email address. For safety, we will not guess which donation to change. Please contact us.'],
            'monthly_manage_stripe_unavailable' => ['Monthly donation management: Stripe-unavailable message', 'Stripe could not be reached, so no payment change can be verified right now. Please try again later.'],
            'monthly_manage_manual' => ['Monthly donation management: cash/check description', 'This account is recorded as a cash or check monthly donor. There is no card amount to change, but the monthly-donor status can be cancelled.'],
            'monthly_manage_external' => ['Monthly donation management: external-payment description', 'This record has a monthly-donor status, but we cannot see a Stripe subscription to change. This is usually an LGL Forms donation; staff must change or cancel it in LGL Forms.'],
            'monthly_manage_none' => ['Monthly donation management: no-active-donation result', 'No active monthly donation was found for this account.'],
            'monthly_change_stripe_unavailable' => ['Monthly donation amount change: Stripe-unavailable result', 'Stripe is unavailable, so the amount was not changed.'],
            'monthly_change_not_automatic' => ['Monthly donation amount change: automatic-change unavailable result', 'This donation cannot be changed automatically.'],
            'monthly_change_success' => ['Monthly donation amount change: success (keep {amount})', 'Your monthly donation is now {amount}. The new amount begins with the next scheduled charge; no mid-cycle charge was made.'],
            'monthly_change_lgl_advisory' => ['Monthly donation amount change: LGL Forms advisory', 'Because LGL Forms may keep its own schedule details, office staff should verify that record.'],
            'monthly_change_lgl_retry' => ['Monthly donation amount change: LGL retry result', 'The LGL status mirror is queued for retry.'],
            'monthly_change_lgl_review' => ['Monthly donation amount change: LGL review result', 'The local and LGL status records needs staff review.'],
            'monthly_cancel_shared_cash' => ['Monthly donation cancellation: shared cash/check warning', 'This cash/check register email belongs to more than one contact record. Staff must update the exact record ID manually; nothing was changed.'],
            'monthly_cancel_shared_unbound_stripe' => ['Monthly donation cancellation: unbound shared-email warning', 'This email belongs to more than one contact record, and the Stripe subscription is not bound to this permanent record ID. Nothing was cancelled.'],
            'monthly_cancel_external' => ['Monthly donation cancellation: LGL Forms instruction', 'No Stripe subscription was found to stop. This donation may be controlled by LGL Forms; staff must cancel it there before changing the local or LGL status.'],
            'monthly_cancel_unverified' => ['Monthly donation cancellation: unverified-Stripe result', 'Stripe could not verify that future charges are stopped. Nothing has been marked inactive.'],
            'monthly_cancel_already_inactive' => ['Monthly donation cancellation: already-inactive result', 'No active monthly donation was found. Your status is already inactive.'],
            'monthly_cancel_local_review' => ['Monthly donation cancellation: local-status review result', 'Your monthly charge was stopped, but the local donor-status mirror needs staff review.'],
            'monthly_cancel_lgl_retry' => ['Monthly donation cancellation: LGL retry result', 'Your monthly donation has been cancelled. The LGL status update is queued for automatic retry.'],
            'monthly_cancel_lgl_review' => ['Monthly donation cancellation: LGL review result', 'Your monthly donation has been cancelled, but the LGL status update needs staff review.'],
            'monthly_cancel_success' => ['Monthly donation cancellation: success', 'Your monthly donation has been cancelled. Future monthly charges have been stopped and your donor status is updated.'],
            'monthly_stripe_ambiguous' => ['Monthly donation management: ambiguous Stripe-charge warning', 'More than one active monthly charge uses this email address. We will not guess which one belongs to this person; staff must verify it in LGL Forms or Stripe.'],
            'monthly_stripe_identity_mismatch' => ['Monthly donation management: Stripe identity-mismatch result', 'The Stripe subscription is linked to a different contact record. Nothing was changed.'],
            'monthly_stripe_unsupported_price' => ['Monthly donation management: unsupported Stripe-price result', 'Stripe can cancel this subscription, but its billing structure is not safe for an automatic amount change. Office staff must change it in LGL Forms.'],
            'monthly_stripe_invalid_amount' => ['Monthly donation management: invalid amount (keep {min_amount} and {max_amount})', 'Enter a monthly amount from {min_amount} through {max_amount}.'],
            'monthly_stripe_not_found' => ['Monthly donation management: no Stripe subscription result', 'No active Stripe monthly subscription was found for this verified account.'],
            'monthly_stripe_cancel_unverified' => ['Monthly donation cancellation: Stripe-verification failure', 'Stripe could not verify that the monthly charge was stopped. Nothing has been marked inactive.'],
            'monthly_stripe_change_unverified' => ['Monthly donation amount change: Stripe-verification failure', 'Stripe did not confirm the new amount, so we are not reporting the change as complete.'],
            'monthly_stripe_generic_failure' => ['Monthly donation management: generic Stripe failure', 'Stripe could not be reached, so nothing was changed. Please try again later.'],
        ];
        foreach ($items as $key => [$label, $text]) {
            if (isset($defaults[$key])) continue;
            $defaults[$key] = [
                'label' => $label,
                'text' => $text,
                'group' => 'Monthly donation management',
            ];
            if ($key === 'monthly_change_success') {
                $defaults[$key]['required_tokens'] = ['amount'];
            } elseif ($key === 'monthly_stripe_invalid_amount') {
                $defaults[$key]['required_tokens'] = ['min_amount', 'max_amount'];
            }
        }
        return $defaults;
    }

    private static function wording(string $key, string $fallback): string
    {
        $value = function_exists('bg_str') ? bg_str($key, $fallback) : $fallback;
        $value = trim(wp_strip_all_tags((string) $value));
        return $value !== '' ? $value : $fallback;
    }

    /** @param array<string, string> $tokens */
    private static function templatedWording(string $key, string $fallback, array $tokens): string
    {
        $template = self::wording($key, $fallback);
        foreach (array_keys($tokens) as $token) {
            if (!str_contains($template, $token)) {
                $template = $fallback;
                break;
            }
        }
        return strtr($template, $tokens);
    }

    /** @return array{yes: bool, reason: string} */
    public function status(string $email, int $lglId = 0): array
    {
        return $this->container->get('registration.monthly_donor')->status($email, $lglId);
    }

    /**
     * Describe what the signed-in constituent can safely do. Stripe changes
     * are offered only when exactly one live monthly subscription belongs to
     * the verified address. LGL Forms-only records remain a staff workflow.
     *
     * @return array{mode: string, message: string, subscription?: array<string, mixed>}
     */
    public function management(int $localId, int $lglId, string $email): array
    {
        $email = strtolower(trim($email));
        if ($localId < 1 || !is_email($email)) {
            return ['mode' => 'blocked', 'message' => self::wording(
                'monthly_manage_invalid_record',
                'Your verified constituent record could not be resolved.'
            )];
        }
        $monthly = $this->container->get('registration.monthly_donor');
        $status = $monthly->status($email, $lglId);
        $onRegister = in_array($email, $monthly->register(), true);
        if ($onRegister && $this->emailShared($email)) {
            return [
                'mode' => 'blocked',
                'message' => self::wording(
                    'monthly_manage_shared_cash',
                    'This cash/check register email belongs to more than one contact record. Staff must update the exact record ID manually so one household member is not changed for another.'
                ),
            ];
        }

        if ($this->container->has('stripe') && $this->container->get('stripe')->canUseApi()) {
            $stripe = $this->container->get('stripe');
            $manageable = $stripe->manageableMonthlySubscription($email, $localId);
            if (!empty($manageable['ok'])) {
                if ($this->emailShared($email)
                    && empty($manageable['subscription']['identity_bound'])
                ) {
                    return [
                        'mode' => 'blocked',
                        'message' => self::wording(
                            'monthly_manage_shared_unbound_stripe',
                            'This email belongs to more than one contact record, and the Stripe subscription is not bound to this permanent record ID. For safety, we will not guess which person owns the charge. Please contact us.'
                        ),
                    ];
                }
                return [
                    'mode' => 'stripe',
                    'message' => self::wording(
                        'monthly_manage_stripe_found',
                        'Stripe found one monthly donation for this verified account.'
                    ),
                    'subscription' => (array) ($manageable['subscription'] ?? []),
                ];
            }
            $reason = (string) ($manageable['reason'] ?? 'stripe_unavailable');
            if ($reason === 'identity_mismatch') {
                return [
                    'mode' => 'other_person',
                    'message' => self::wording(
                        'monthly_manage_other_person',
                        'The monthly donation using this shared email belongs to a different permanent record ID.'
                    ),
                ];
            }
            if ($reason === 'ambiguous') {
                return [
                    'mode' => 'blocked',
                    'message' => self::wording(
                        'monthly_manage_ambiguous',
                        'More than one person or monthly charge may use this email address. For safety, we will not guess which donation to change. Please contact us.'
                    ),
                ];
            }
            if ($reason === 'stripe_unavailable') {
                return [
                    'mode' => 'blocked',
                    'message' => self::wording(
                        'monthly_manage_stripe_unavailable',
                        'Stripe could not be reached, so no payment change can be verified right now. Please try again later.'
                    ),
                ];
            }
        }

        if ($onRegister) {
            return [
                'mode' => 'manual',
                'message' => self::wording(
                    'monthly_manage_manual',
                    'This account is recorded as a cash or check monthly donor. There is no card amount to change, but the monthly-donor status can be cancelled.'
                ),
            ];
        }
        if (!empty($status['yes'])) {
            return [
                'mode' => 'external',
                'message' => self::wording(
                    'monthly_manage_external',
                    'This record has a monthly-donor status, but we cannot see a Stripe subscription to change. This is usually an LGL Forms donation; staff must change or cancel it in LGL Forms.'
                ),
            ];
        }
        return ['mode' => 'none', 'message' => self::wording(
            'monthly_manage_none',
            'No active monthly donation was found for this account.'
        )];
    }

    /**
     * @return array{ok: bool, message: string, lgl_synced: bool, sync_pending: bool}
     */
    public function changeAmount(
        int $localId,
        int $lglId,
        string $email,
        string $subscriptionId,
        int $amountCents
    ): array {
        $email = strtolower(trim($email));
        if ($localId < 1 || !is_email($email)) {
            return ['ok' => false, 'message' => self::wording(
                'monthly_manage_invalid_record',
                'Your verified constituent record could not be resolved.'
            ), 'lgl_synced' => false, 'sync_pending' => false];
        }
        if (!$this->container->has('stripe') || !$this->container->get('stripe')->canUseApi()) {
            return ['ok' => false, 'message' => self::wording(
                'monthly_change_stripe_unavailable',
                'Stripe is unavailable, so the amount was not changed.'
            ), 'lgl_synced' => false, 'sync_pending' => false];
        }
        $state = $this->management($localId, $lglId, $email);
        if (($state['mode'] ?? '') !== 'stripe') {
            return [
                'ok' => false,
                'message' => (string) ($state['message'] ?? self::wording(
                    'monthly_change_not_automatic',
                    'This donation cannot be changed automatically.'
                )),
                'lgl_synced' => false,
                'sync_pending' => false,
            ];
        }
        $result = $this->container->get('stripe')->updateMonthlySubscriptionAmount(
            $email,
            $localId,
            $subscriptionId,
            $amountCents
        );
        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'message' => $this->stripeFailureMessage((string) ($result['reason'] ?? 'stripe_unavailable'), 'change'),
                'lgl_synced' => false,
                'sync_pending' => false,
            ];
        }

        $store = $this->container->get('constituents.store');
        $fieldId = $store->ensureField(CoreFields::MONTHLY_DONOR, 'Monthly Donor', false);
        $localOk = $fieldId > 0 && $store->setValue($localId, $fieldId, 'Yes');
        $remote = $this->syncLglStatus($localId, $lglId, $email, true);
        $syncPending = false;
        if (!$remote['ok'] && (int) $remote['lgl_id'] > 0) {
            $syncPending = $this->container->get('jobs')->enqueue(self::RETRY_JOB, [
                'local_id' => $localId,
                'lgl_id' => (int) $remote['lgl_id'],
                'active' => true,
                'attempt' => 1,
            ], 60);
        }
        if ($this->container->has('constituents.activity')) {
            $this->container->get('constituents.activity')->append(
                $localId,
                'subscription',
                'Monthly donation amount changed by constituent.',
                [
                    'old_amount_cents' => (int) ($result['old_amount'] ?? 0),
                    'new_amount_cents' => (int) ($result['new_amount'] ?? $amountCents),
                    'lgl_status_updated' => (bool) $remote['ok'],
                ]
            );
        }
        $this->container->get('registration.monthly_donor')->clearCache($email, (int) $remote['lgl_id']);
        $amount = \BosqueGrande\Core\Money::format($amountCents);
        $message = self::templatedWording(
            'monthly_change_success',
            'Your monthly donation is now {amount}. The new amount begins with the next scheduled charge; no mid-cycle charge was made.',
            ['{amount}' => $amount]
        );
        $message .= ' ' . self::wording(
            'monthly_change_lgl_advisory',
            'Because LGL Forms may keep its own schedule details, office staff should verify that record.'
        );
        if (!$localOk || !$remote['ok']) {
            $message .= $syncPending
                ? ' ' . self::wording('monthly_change_lgl_retry', 'The LGL status mirror is queued for retry.')
                : ' ' . self::wording('monthly_change_lgl_review', 'The local and LGL status records needs staff review.');
        }
        return [
            'ok' => true,
            'message' => $message,
            'lgl_synced' => (bool) $remote['ok'],
            'sync_pending' => $syncPending,
        ];
    }

    /**
     * @return array{
     *   ok: bool, message: string, cancelled: int,
     *   lgl_synced: bool, sync_pending: bool
     * }
     */
    public function cancel(int $localId, int $lglId, string $email, string $subscriptionId = ''): array
    {
        $email = strtolower(trim($email));
        if ($localId < 1 || !is_email($email)) {
            return $this->failure(self::wording(
                'monthly_manage_invalid_record',
                'Your verified constituent record could not be resolved.'
            ));
        }

        $monthly = $this->container->get('registration.monthly_donor');
        $before = $monthly->status($email, $lglId);
        $onRegister = in_array($email, $monthly->register(), true);
        if ($onRegister && $this->emailShared($email)) {
            return $this->failure(
                self::wording(
                    'monthly_cancel_shared_cash',
                    'This cash/check register email belongs to more than one contact record. Staff must update the exact record ID manually; nothing was changed.'
                )
            );
        }
        $stripeResult = ['ok' => true, 'cancelled' => 0, 'remaining' => 0];
        $stripeChecked = false;

        if ($this->container->has('stripe')) {
            $stripe = $this->container->get('stripe');
            if ($stripe->canUseApi()) {
                $stripeChecked = true;
                $manageable = $stripe->manageableMonthlySubscription($email, $localId);
                if (!empty($manageable['ok'])) {
                    if ($this->emailShared($email)
                        && empty($manageable['subscription']['identity_bound'])
                    ) {
                        return $this->failure(
                            self::wording(
                                'monthly_cancel_shared_unbound_stripe',
                                'This email belongs to more than one contact record, and the Stripe subscription is not bound to this permanent record ID. Nothing was cancelled.'
                            )
                        );
                    }
                    $stripeResult = $stripe->cancelManageableMonthlySubscription(
                        $email,
                        $localId,
                        $subscriptionId
                    );
                    if (!$stripeResult['ok']) {
                        return $this->failure(
                            $this->stripeFailureMessage((string) ($stripeResult['reason'] ?? 'verification_failed'), 'cancel'),
                            (int) $stripeResult['cancelled']
                        );
                    }
                } else {
                    $reason = (string) ($manageable['reason'] ?? 'stripe_unavailable');
                    if (!in_array($reason, ['not_found'], true)) {
                        return $this->failure($this->stripeFailureMessage($reason, 'cancel'));
                    }
                }
            }
        }

        // A cash/check register entry has no charge to stop. A BG/LGL status
        // without a visible Stripe subscription may still be an LGL Forms
        // schedule, so it must never be silently marked cancelled here.
        if ((int) $stripeResult['cancelled'] === 0 && !$onRegister && !empty($before['yes'])) {
            return $this->failure($stripeChecked
                ? self::wording(
                    'monthly_cancel_external',
                    'No Stripe subscription was found to stop. This donation may be controlled by LGL Forms; staff must cancel it there before changing the local or LGL status.'
                )
                : self::wording(
                    'monthly_cancel_unverified',
                    'Stripe could not verify that future charges are stopped. Nothing has been marked inactive.'
                ));
        }

        // A stale page or repeated request is deliberately idempotent.
        if (!$before['yes'] && !$onRegister && (int) $stripeResult['cancelled'] === 0) {
            $monthly->clearCache($email, $lglId);
            return [
                'ok' => true,
                'message' => self::wording(
                    'monthly_cancel_already_inactive',
                    'No active monthly donation was found. Your status is already inactive.'
                ),
                'cancelled' => 0,
                'lgl_synced' => true,
                'sync_pending' => false,
            ];
        }

        $remote = $this->syncLglStatus($localId, $lglId, $email, false);
        $localOk = $monthly->recordCancellation($email, $localId, (int) $remote['lgl_id']);

        if ($this->container->has('constituents.activity')) {
            $this->container->get('constituents.activity')->append(
                $localId,
                'subscription',
                'Monthly donation cancelled by constituent.',
                [
                    'stripe_subscriptions_cancelled' => (int) $stripeResult['cancelled'],
                    'lgl_status_updated' => (bool) $remote['ok'],
                ]
            );
        }

        $syncPending = false;
        if (!$remote['ok'] && (int) $remote['lgl_id'] > 0) {
            $syncPending = $this->container->get('jobs')->enqueue(self::RETRY_JOB, [
                'local_id' => $localId,
                'lgl_id' => (int) $remote['lgl_id'],
                'active' => false,
                'attempt' => 1,
            ], 60);
        }

        $this->container->get('logger')->info('profile', 'Monthly donation cancellation processed.', [
            'constituent' => $localId,
            'stripe_subscriptions_cancelled' => (int) $stripeResult['cancelled'],
            'local_status_updated' => $localOk,
            'lgl_status_updated' => (bool) $remote['ok'],
            'lgl_retry_queued' => $syncPending,
        ]);

        if (!$localOk) {
            return [
                'ok' => true,
                'message' => self::wording(
                    'monthly_cancel_local_review',
                    'Your monthly charge was stopped, but the local donor-status mirror needs staff review.'
                ),
                'cancelled' => (int) $stripeResult['cancelled'],
                'lgl_synced' => (bool) $remote['ok'],
                'sync_pending' => $syncPending,
            ];
        }
        if (!$remote['ok']) {
            return [
                'ok' => true,
                'message' => $syncPending
                    ? self::wording(
                        'monthly_cancel_lgl_retry',
                        'Your monthly donation has been cancelled. The LGL status update is queued for automatic retry.'
                    )
                    : self::wording(
                        'monthly_cancel_lgl_review',
                        'Your monthly donation has been cancelled, but the LGL status update needs staff review.'
                    ),
                'cancelled' => (int) $stripeResult['cancelled'],
                'lgl_synced' => false,
                'sync_pending' => $syncPending,
            ];
        }
        return [
            'ok' => true,
            'message' => self::wording(
                'monthly_cancel_success',
                'Your monthly donation has been cancelled. Future monthly charges have been stopped and your donor status is updated.'
            ),
            'cancelled' => (int) $stripeResult['cancelled'],
            'lgl_synced' => true,
            'sync_pending' => false,
        ];
    }

    /** @param array<string, mixed> $args */
    public function retryLgl(array $args): void
    {
        $localId = (int) ($args['local_id'] ?? 0);
        $lglId = (int) ($args['lgl_id'] ?? 0);
        $active = !empty($args['active']);
        $attempt = max(1, (int) ($args['attempt'] ?? 1));
        if ($localId < 1 || $lglId < 1 || !$this->container->has('lgl')) return;

        $lgl = $this->container->get('lgl');
        if ($lgl->hasKey() && $lgl->setMonthlyDonorStatus($lglId, $active)) {
            $this->container->get('logger')->info('profile', 'Deferred LGL donor-status change synchronized.', [
                'constituent' => $localId,
                'lgl_constituent' => $lglId,
                'active' => $active,
                'attempt' => $attempt,
            ]);
            return;
        }
        if ($attempt < 3) {
            $this->container->get('jobs')->enqueue(self::RETRY_JOB, [
                'local_id' => $localId,
                'lgl_id' => $lglId,
                'active' => $active,
                'attempt' => $attempt + 1,
            ], $attempt * 5 * MINUTE_IN_SECONDS);
            return;
        }
        $this->container->get('logger')->error('profile', 'LGL donor-status cancellation exhausted automatic retries.', [
            'constituent' => $localId,
            'lgl_constituent' => $lglId,
        ]);
    }

    /** @return array{ok: bool, lgl_id: int} */
    private function syncLglStatus(int $localId, int $lglId, string $email, bool $active): array
    {
        if (!$this->container->has('lgl')) return ['ok' => false, 'lgl_id' => $lglId];
        $lgl = $this->container->get('lgl');
        if (!$lgl->hasKey()) return ['ok' => false, 'lgl_id' => $lglId];

        if ($lglId < 1) {
            $lglId = $this->matchRemoteId($lgl, $localId, $email);
            if ($lglId > 0) {
                $this->container->get('constituents.store')->linkLgl($localId, $lglId);
            }
        }
        if ($lglId < 1) return ['ok' => false, 'lgl_id' => 0];
        return ['ok' => $lgl->setMonthlyDonorStatus($lglId, $active), 'lgl_id' => $lglId];
    }

    private function matchRemoteId(object $lgl, int $localId, string $email): int
    {
        $store = $this->container->get('constituents.store');
        $local = $store->get($localId);
        if (!$local) return 0;
        $matches = method_exists($lgl, 'findConstituentsByEmail')
            ? (array) $lgl->findConstituentsByEmail($email)
            : array_filter([$lgl->findConstituentByEmail($email)], 'is_array');
        if (method_exists($lgl, 'lastRequestSucceeded') && !$lgl->lastRequestSucceeded()) return 0;
        $details = [];
        foreach ($matches as $match) {
            if (!is_array($match)) continue;
            $id = (int) ($match['id'] ?? 0);
            if ($id < 1) continue;
            $detail = method_exists($lgl, 'getConstituent') ? $lgl->getConstituent($id) : $match;
            if (method_exists($lgl, 'lastRequestSucceeded') && !$lgl->lastRequestSucceeded()) return 0;
            if (is_array($detail)) $details[$id] = $detail;
        }
        if (!$details) return 0;
        $best = 0;
        $bestScore = 1;
        $tied = false;
        foreach ($details as $id => $detail) {
            $p = \BosqueGrande\Modules\Lgl\ConstituentPresenter::prefill($detail);
            $candidate = [
                'first_name' => $p['first_name'], 'last_name' => $p['last_name'],
                'phone' => $p['phone'], 'address1' => $p['street'], 'address2' => $p['address2'],
                'city' => $p['city'], 'state' => $p['state'], 'postal' => $p['zip'],
            ];
            $score = \BosqueGrande\Modules\Constituents\ConstituentStore::identityScore($local, $candidate);
            if ($score > $bestScore) {
                $best = (int) $id;
                $bestScore = $score;
                $tied = false;
            } elseif ($score === $bestScore && $score >= 2) {
                $tied = true;
            }
        }
        if ($best > 0 && !$tied) return $best;
        if (count($details) !== 1) return 0;
        $id = (int) array_key_first($details);
        $p = \BosqueGrande\Modules\Lgl\ConstituentPresenter::prefill($details[$id]);
        $localComplete = trim((string) ($local['first_name'] ?? '')) !== ''
            && trim((string) ($local['last_name'] ?? '')) !== '';
        $remoteComplete = trim((string) $p['first_name']) !== '' && trim((string) $p['last_name']) !== '';
        return $localComplete && $remoteComplete ? 0 : $id;
    }

    /**
     * @return array{
     *   ok: false, message: string, cancelled: int,
     *   lgl_synced: false, sync_pending: false
     * }
     */
    private function failure(string $message, int $cancelled = 0): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'cancelled' => $cancelled,
            'lgl_synced' => false,
            'sync_pending' => false,
        ];
    }

    private function stripeFailureMessage(string $reason, string $action): string
    {
        return match ($reason) {
            'ambiguous' => self::wording(
                'monthly_stripe_ambiguous',
                'More than one active monthly charge uses this email address. We will not guess which one belongs to this person; staff must verify it in LGL Forms or Stripe.'
            ),
            'identity_mismatch' => self::wording(
                'monthly_stripe_identity_mismatch',
                'The Stripe subscription is linked to a different contact record. Nothing was changed.'
            ),
            'unsupported_price' => self::wording(
                'monthly_stripe_unsupported_price',
                'Stripe can cancel this subscription, but its billing structure is not safe for an automatic amount change. Office staff must change it in LGL Forms.'
            ),
            'invalid_amount' => self::templatedWording(
                'monthly_stripe_invalid_amount',
                'Enter a monthly amount from {min_amount} through {max_amount}.',
                ['{min_amount}' => '$1.00', '{max_amount}' => '$100,000.00']
            ),
            'not_found' => self::wording(
                'monthly_stripe_not_found',
                'No active Stripe monthly subscription was found for this verified account.'
            ),
            'verification_failed' => $action === 'cancel'
                ? self::wording(
                    'monthly_stripe_cancel_unverified',
                    'Stripe could not verify that the monthly charge was stopped. Nothing has been marked inactive.'
                )
                : self::wording(
                    'monthly_stripe_change_unverified',
                    'Stripe did not confirm the new amount, so we are not reporting the change as complete.'
                ),
            default => self::wording(
                'monthly_stripe_generic_failure',
                'Stripe could not be reached, so nothing was changed. Please try again later.'
            ),
        };
    }

    private function emailShared(string $email): bool
    {
        $store = $this->container->get('constituents.store');
        return $store->available() && count($store->idsByEmail($email)) > 1;
    }
}
