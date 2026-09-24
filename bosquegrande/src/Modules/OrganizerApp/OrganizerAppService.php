<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\OrganizerApp;

use BosqueGrande\Core\Logger;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Constituents\RegionalGroups;
use BosqueGrande\Modules\Correspondence\Groups;
use BosqueGrande\Modules\OrganizerPortal\OrganizerAccess;
use BosqueGrande\Modules\OrganizerPortal\OrganizerRepository;
use BosqueGrande\Modules\Payments\MonthlyDonationEnrollment;
use BosqueGrande\Modules\Registration\DemographicFields;
use BosqueGrande\Modules\Registration\MonthlyDonor;
use BosqueGrande\Modules\Registration\RegistrationConfig;
use BosqueGrande\Modules\Registration\RegistrationRepository;
use BosqueGrande\Modules\Registration\SurveyFields;
use BosqueGrande\Modules\Registration\Tiers;
use BosqueGrande\Modules\Revenue\Ledger;
use BosqueGrande\Modules\Engagement\HistoricalParticipation;

if (!defined('ABSPATH')) exit;

/** Application-level organizer workflows, shared by REST and staff tools. */
final class OrganizerAppService
{
    private const SESSION_TTL = 15 * MINUTE_IN_SECONDS;
    private const MAX_MONEY_CENTS = 10000000; // $100,000 per line.

    public function __construct(
        private OrganizerAppRepository $app,
        private OrganizerRepository $portal,
        private OrganizerAccess $access,
        private ConstituentStore $constituents,
        private RegionalGroups $regions,
        private RegistrationRepository $registrations,
        private Ledger $ledger,
        private Groups $groups,
        private MonthlyDonor $monthlyDonor,
        private Logger $logger,
        private HistoricalParticipation $history,
    ) {
    }

    public function available(): bool
    {
        return $this->app->available();
    }

    /** @return array<string,mixed>|\WP_Error */
    public function bootstrap(int $userId): array|\WP_Error
    {
        if (!$this->access->canUsePortal($userId)) return $this->error('forbidden', OrganizerAppWording::text('organizer_app_api_access_required', 'Organizer access is required.'), 403);
        if (!$this->app->available()) return $this->error('schema_unavailable', OrganizerAppWording::text('organizer_app_api_schema_unavailable', 'The organizer app database upgrade has not completed.'), 503);
        $user = get_user_by('id', $userId);
        $constituentId = \BosqueGrande\Core\UserLink::get($userId);
        $events = $this->events($userId);
        return [
            'version' => BG_VERSION,
            'user' => [
                'id' => $userId,
                'name' => $user instanceof \WP_User ? (string) $user->display_name : OrganizerAppWording::text('organizer_app_user_fallback', 'Organizer'),
                'is_staff' => user_can($userId, 'manage_options'),
                'constituent_id' => $constituentId,
                'my_profile_url' => function_exists('bgev_public_profile_url') ? bgev_public_profile_url() : home_url('/'),
            ],
            'events' => $events,
            'monthly_url' => MonthlyDonationEnrollment::url(),
            'tiers' => $this->tierOptions(),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function events(int $userId): array
    {
        $events = $this->portal->eventsForUser($userId, user_can($userId, 'manage_options'));
        $out = [];
        foreach ($events as $event) {
            $out[] = $this->eventPayload($event, $userId, false);
        }
        return $out;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function event(int $userId, int $eventId): array|\WP_Error
    {
        if (!$this->access->canEvent($userId, $eventId, 'view')) return $this->error('forbidden_event', OrganizerAppWording::text('organizer_app_api_event_forbidden', 'You are not assigned to this event.'), 403);
        $event = get_post($eventId);
        if (!$event instanceof \WP_Post) return $this->error('missing_event', OrganizerAppWording::text('organizer_app_api_event_missing', 'The event could not be found.'), 404);
        $payload = $this->eventPayload($event, $userId, true);
        $payload['tenders'] = $this->access->canEvent($userId, $eventId, 'tender')
            ? array_map([$this, 'publicTender'], $this->app->tendersForEvent($eventId))
            : [];
        return $payload;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function roster(int $userId, int $eventId): array|\WP_Error
    {
        if (!$this->access->canEvent($userId, $eventId, 'roster')) return $this->error('forbidden_roster', OrganizerAppWording::text('organizer_app_api_roster_forbidden', 'You cannot view this roster.'), 403);
        $rows = $this->portal->roster($eventId);
        foreach ($rows as &$row) {
            $registrationId = (int) $row['registration_id'];
            if (!empty($row['contact_withheld'])) {
                $row['name'] = OrganizerAppWording::format('organizer_app_private_attendee_name', 'Private attendee #{id}', ['id' => $registrationId]);
                $row['email'] = '';
                $row['phone'] = '';
                $row['address1'] = '';
                $row['address2'] = '';
                $row['city'] = '';
                $row['state'] = '';
                $row['postal'] = '';
            } elseif (trim((string) $row['name']) === '') {
                $row['name'] = OrganizerAppWording::format('organizer_app_attendee_name', 'Attendee #{id}', ['id' => $registrationId]);
            }
            $row['attendance_revision'] = (string) ($row['attendance_revision'] ?? '');
        }
        unset($row);
        return [
            'event_id' => $eventId,
            'rows' => $rows,
            'state' => $this->eventStateForUser($userId, $eventId),
        ];
    }

    /**
     * Prefill a returning attendee only after exact name plus exact email or
     * phone match, and only when the system can prove attendance before this event.
     * The endpoint never returns candidate lists, fuzzy suggestions,
     * demographic answers, or donation history.
     * @return array<string,mixed>|\WP_Error
     */
    public function attendeeLookup(int $userId, int $eventId, array $input): array|\WP_Error
    {
        if (!$this->access->canEvent($userId, $eventId, 'walk_in')) {
            return $this->error('forbidden_lookup', OrganizerAppWording::text('organizer_app_api_lookup_forbidden', 'You cannot use attendee sign-in for this event.'), 403);
        }
        $first = bg_substr(sanitize_text_field((string) ($input['first_name'] ?? '')), 0, 100);
        $last = bg_substr(sanitize_text_field((string) ($input['last_name'] ?? '')), 0, 100);
        $email = strtolower(sanitize_email((string) ($input['email'] ?? '')));
        $phone = bg_substr(sanitize_text_field((string) ($input['phone'] ?? '')), 0, 50);
        if ($first === '' || $last === '' || (!is_email($email) && ConstituentStore::normPhone($phone) === '')) {
            return $this->error('lookup_fields_required', OrganizerAppWording::text('organizer_app_api_lookup_fields', 'Enter first name, last name, and either the saved email or phone number.'), 400);
        }
        // Each lookup is audited and an organizer gets a small hourly budget,
        // so the form cannot be used to search the contact database.
        $budgetKey = 'bg_org_lookup_' . $userId;
        $used = (int) get_transient($budgetKey);
        if ($used >= 40) {
            return $this->error('lookup_rate_limited', OrganizerAppWording::text('organizer_app_api_lookup_limited', 'Too many attendee lookups. Try again later or continue with first-time attendee entry.'), 429);
        }
        set_transient($budgetKey, $used + 1, HOUR_IN_SECONDS);
        $this->portal->audit($userId, $eventId, 0, 'attendee_lookup');

        $nameIds = array_map('intval', $this->constituents->idsByName($first, $last));
        $contactIds = [];
        if (is_email($email)) $contactIds = array_merge($contactIds, $this->constituents->idsByEmail($email));
        if (ConstituentStore::normPhone($phone) !== '') $contactIds = array_merge($contactIds, $this->constituents->idsByPhone($phone));
        $matches = array_values(array_unique(array_intersect($nameIds, array_map('intval', $contactIds))));
        if (count($matches) !== 1) {
            return [
                'matched' => false,
                'match_status' => count($matches) > 1 ? 'ambiguous' : 'not_found',
                'message' => count($matches) > 1
                    ? OrganizerAppWording::text('organizer_app_api_lookup_ambiguous', 'More than one record matches. Staff review is required; no contact details were revealed.')
                    : OrganizerAppWording::text('organizer_app_api_lookup_none', 'No returning-attendee record matched those exact details. Continue with first-time attendee entry.'),
            ];
        }
        $constituentId = (int) $matches[0];
        $eventDate = (string) get_post_meta($eventId, '_bg_start_date', true);
        $newcomerStatus = $this->history->newcomerStatus($constituentId, $eventDate, $eventId);
        if ($newcomerStatus === HistoricalParticipation::NEWCOMER_UNKNOWN) {
            return [
                'matched' => false,
                'match_status' => 'history_unknown',
                'message' => OrganizerAppWording::text('organizer_app_api_history_unknown', 'The identity matched, but the system cannot date one or more Past Events entries. Staff review is required; no saved contact details were revealed.'),
            ];
        }
        if ($newcomerStatus === HistoricalParticipation::NEWCOMER) {
            return [
                'matched' => false,
                'match_status' => 'first_time',
                'message' => OrganizerAppWording::text('organizer_app_api_first_time', 'The identity matched, but there is no event before this one. Continue with first-time attendee entry.'),
            ];
        }
        $record = $this->constituents->get($constituentId);
        if (!$record || ($record['status'] ?? '') !== 'active') {
            return ['matched' => false, 'match_status' => 'not_found', 'message' => OrganizerAppWording::text('organizer_app_api_record_unavailable', 'The saved record is not available. Continue with first-time attendee entry.')];
        }
        // Someone who chose not to share contact details with organizers gets
        // only what the organizer already typed.
        if (!$this->constituents->contactSharingAllowed($constituentId)) {
            return [
                'matched' => true,
                'match_status' => 'returning',
                'constituent_id' => $constituentId,
                'message' => OrganizerAppWording::text('organizer_app_api_returning_private', 'Returning attendee found. They chose not to share contact details, so only what you entered is filled in.'),
                'prefill' => [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => is_email($email) ? $email : '',
                    'phone' => $phone,
                    'street' => '', 'city' => '', 'state' => '', 'zip' => '',
                ],
            ];
        }
        return [
            'matched' => true,
            'match_status' => 'returning',
            'constituent_id' => $constituentId,
            'message' => OrganizerAppWording::text('organizer_app_api_returning_found', 'Returning attendee found. Contact fields were prefilled from the encrypted constituent record.'),
            'prefill' => [
                'first_name' => (string) ($record['first_name'] ?? ''),
                'last_name' => (string) ($record['last_name'] ?? ''),
                'email' => (string) ($record['email'] ?? ''),
                'phone' => (string) ($record['phone'] ?? ''),
                'street' => trim((string) ($record['address1'] ?? '') . (($record['address2'] ?? '') !== '' ? ' ' . (string) $record['address2'] : '')),
                'city' => (string) ($record['city'] ?? ''),
                'state' => (string) ($record['state'] ?? ''),
                'zip' => (string) ($record['postal'] ?? ''),
            ],
        ];
    }

    /** @return array<string,mixed>|\WP_Error */
    public function attendance(int $userId, int $eventId, array $input): array|\WP_Error
    {
        if (!$this->access->canEvent($userId, $eventId, 'check_in')) {
            return $this->error('forbidden_checkin', OrganizerAppWording::text('organizer_app_api_attendance_forbidden', 'You cannot change attendance for this event.'), 403);
        }
        return $this->withEventLock($eventId, function () use ($userId, $eventId, $input): array|\WP_Error {
            if ($this->app->writeLocked($eventId)) {
                return $this->error('event_closed', OrganizerAppWording::text('organizer_app_api_event_closed_reopen', 'This event is locked by its submitted closeout. Staff must reopen it first.'), 409);
            }
            $registrationId = absint($input['registration_id'] ?? 0);
            $status = sanitize_key((string) ($input['status'] ?? ''));
            $revision = sanitize_text_field((string) ($input['revision'] ?? ''));
            $result = $this->app->updateAttendance($eventId, $registrationId, $status, $userId, $revision);
            if (!$result['ok']) {
                if ($result['conflict']) {
                    return new \WP_Error('attendance_conflict', 'Attendance changed on another device. The current status has been returned.', [
                        'status' => 409,
                        'current' => $result,
                    ]);
                }
                return $this->error('attendance_failed', OrganizerAppWording::text('organizer_app_api_attendance_failed', 'Attendance could not be saved for this active registration.'), 400);
            }
            $this->portal->audit($userId, $eventId, $registrationId, 'app_attendance_changed', $status);
            return ['attendance' => $result, 'state' => $this->eventStateForUser($userId, $eventId)];
        });
    }

    /** @return array<string,mixed>|\WP_Error */
    public function createWalkIn(int $userId, int $eventId, array $input): array|\WP_Error
    {
        if (!$this->access->canEvent($userId, $eventId, 'walk_in')) {
            return $this->error('forbidden_walkin', OrganizerAppWording::text('organizer_app_api_walkin_forbidden', 'You cannot register walk-ins for this event.'), 403);
        }
        return $this->withEventLock($eventId, function () use ($userId, $eventId, $input): array|\WP_Error {
            if ($this->app->writeLocked($eventId)) {
                return $this->error('event_closed', OrganizerAppWording::text('organizer_app_api_event_closed', 'This event is locked by its submitted closeout.'), 409);
            }

            $clientKey = $this->clientKey($input['idempotency_key'] ?? '');
            if ($clientKey === '') {
                return $this->error('missing_idempotency', OrganizerAppWording::text('organizer_app_api_retry_refresh', 'The app could not establish a safe retry key. Refresh and try again.'), 400);
            }
            $record = $this->cleanWalkIn($input);
            if ($record['first_name'] === '' || $record['last_name'] === '') {
                return $this->error('name_required', OrganizerAppWording::text('organizer_app_api_name_required', 'First and last name are required.'), 400);
            }
            $usableContact = is_email($record['email'])
                || ConstituentStore::normPhone($record['phone']) !== ''
                || ($record['city'] !== '' && $record['state'] !== '');
            if (!$usableContact) {
                return $this->error('contact_required', OrganizerAppWording::text('organizer_app_api_contact_required', 'Enter an email, a usable phone number, or both town and state.'), 400);
            }

            $tier = sanitize_key((string) ($input['tier'] ?? Tiers::defaultKey($eventId)));
            if (!Tiers::offeredFor($tier, $eventId)) return $this->error('invalid_tier', OrganizerAppWording::text('organizer_app_api_tier_invalid', 'Choose a valid registration tier.'), 400);

            $key = hash('sha256', 'walkin|' . $eventId . '|' . $userId . '|' . $clientKey);
            $requestJson = wp_json_encode(['record' => $record, 'tier' => $tier]);
            $requestHash = hash('sha256', is_string($requestJson) ? $requestJson : serialize([$record, $tier]));
            $existingIntake = $this->app->intakeByKey($key);
            if ($existingIntake) {
                if (!hash_equals((string) ($existingIntake->request_hash ?? ''), $requestHash)) {
                    return $this->error('idempotency_conflict', OrganizerAppWording::text('organizer_app_api_walkin_retry_conflict', 'This retry key was already used for different walk-in details. Refresh the form before submitting another person.'), 409);
                }
                $savedRegistration = $this->app->registration($eventId, (int) $existingIntake->registration_id);
                return [
                    'idempotent' => true,
                    'intake_id' => (int) $existingIntake->id,
                    'registration_id' => (int) $existingIntake->registration_id,
                    'constituent_id' => (int) $existingIntake->constituent_id,
                    'match_status' => (string) $existingIntake->match_status,
                    'review_required' => (string) $existingIntake->review_status === 'pending',
                    'display_name' => trim($record['first_name'] . ' ' . $record['last_name']),
                    'tier_amount_cents' => (int) ($savedRegistration->amount_cents ?? 0),
                    'state' => $this->eventStateForUser($userId, $eventId),
                ];
            }

            // Each new walk-in is budgeted like attendee lookups, so the form
            // cannot be used to probe the contact database.
            $budgetKey = 'bg_org_walkin_' . $userId;
            $used = (int) get_transient($budgetKey);
            if ($used >= 200) {
                return $this->error('walkin_rate_limited', OrganizerAppWording::text('organizer_app_api_walkin_limited', 'Too many walk-ins from this account in the last hour. Ask staff to continue.'), 429);
            }
            set_transient($budgetKey, $used + 1, HOUR_IN_SECONDS);

            // Use a saved record only when the name and a typed email or phone
            // point to the same single person, as in the attendee lookup. A
            // name alone, or a name and town, never binds to a saved record,
            // so a walk-in cannot put someone else's contact details on this
            // roster. Near matches get a new record held for staff review.
            $nameIds = array_map('intval', $this->constituents->idsByName($record['first_name'], $record['last_name']));
            $contactIds = [];
            if (is_email($record['email'])) {
                $contactIds = array_merge($contactIds, $this->constituents->idsByEmail($record['email']));
            }
            if (ConstituentStore::normPhone($record['phone']) !== '') {
                $contactIds = array_merge($contactIds, $this->constituents->idsByPhone($record['phone']));
            }
            $contactIds = array_map('intval', $contactIds);
            $confirmed = array_values(array_unique(array_intersect($nameIds, $contactIds)));
            $constituentId = count($confirmed) === 1 ? $this->constituents->disambiguateIds($confirmed, $record) : 0;
            $candidates = array_values(array_unique(array_merge($nameIds, $contactIds)));
            $matchStatus = $constituentId > 0 ? 'matched' : ($candidates ? 'ambiguous' : 'new');
            $reviewStatus = $matchStatus === 'ambiguous' ? 'pending' : 'not_required';
            $newConstituent = false;
            $eventDate = (string) get_post_meta($eventId, '_bg_start_date', true);
            // An ambiguous match creates a provisional constituent so check-in can
            // continue, but it must not be treated as a verified newcomer. That
            // would risk sending a first-visit message to an existing person
            // whose identity still requires staff resolution.
            $newcomerEligible = $matchStatus === 'ambiguous'
                ? false
                : ($constituentId > 0
                    ? $this->history->newcomerStatus($constituentId, $eventDate, $eventId)
                        === HistoricalParticipation::NEWCOMER
                    : true);
            $preExistingRegistration = $constituentId > 0
                ? $this->app->activeRegistrationFor($eventId, $constituentId)
                : null;
            $hasActiveRegistration = $preExistingRegistration
                && in_array((string) $preExistingRegistration->status, ['confirmed', 'paid'], true);

            // A matched constituent may have omitted the email at the door.
            // Use the encrypted current profile email for donor verification
            // when this flow actually needs to create a new registration.
            if (!$hasActiveRegistration && Tiers::isGated($tier) && $this->monthlyDonor->enforced()) {
                $verificationEmail = $record['email'];
                if ($constituentId > 0) {
                    $matchedProfile = $this->constituents->get($constituentId) ?: [];
                    $profileEmail = sanitize_email((string) ($matchedProfile['email'] ?? ''));
                    if (is_email($profileEmail)) $verificationEmail = $profileEmail;
                }
                if (!$this->monthlyDonor->isDonor($verificationEmail)) {
                    return $this->error('monthly_not_verified', OrganizerAppWording::text('organizer_app_api_monthly_unverified', 'Monthly-donor admission could not be verified. Choose another tier or complete enrollment.'), 409);
                }
            }

            $registrationAmountCents = 0;

            if (!$this->constituents->beginAtomic()) {
                return $this->error('transaction_failed', OrganizerAppWording::text('organizer_app_api_walkin_transaction', 'The walk-in could not be started safely.'), 500);
            }
            try {
                if ($constituentId < 1) {
                    $constituentId = $this->constituents->create($record);
                    if ($constituentId < 1) throw new \RuntimeException('Constituent creation failed.');
                    $newConstituent = true;
                }

                $registration = $this->app->activeRegistrationFor($eventId, $constituentId);
                $registrationId = 0;
                if ($registration && in_array((string) $registration->status, ['confirmed', 'paid'], true)) {
                    $registrationId = (int) $registration->id;
                    $registrationAmountCents = (int) $registration->amount_cents;
                    $matchStatus = 'existing_registration';
                } elseif ($registration) {
                    $this->constituents->rollbackAtomic();
                    $this->logger->warn('organizer_app', 'Walk-in stopped for unfinished registration.', [
                        'event' => $eventId,
                        'organizer' => $userId,
                    ]);
                    return $this->error(
                        'registration_unfinished',
                        OrganizerAppWording::text(
                            'organizer_app_api_registration_unfinished',
                            'This person already has an unfinished online registration. The app did not change or confirm it. Complete it online or ask staff to resolve it.'
                        ),
                        409
                    );
                } else {
                    $profile = $this->constituents->get($constituentId) ?: [];
                    $registrationAmountCents = Tiers::amount($tier);
                    $registrationId = $this->registrations->insert([
                        'event_post_id' => $eventId,
                        'lgl_event_id' => 0,
                        'lgl_constituent_id' => (int) ($profile['lgl_constituent_id'] ?? 0),
                        'bg_constituent_id' => $constituentId,
                        'tier' => $tier,
                        'amount_cents' => $registrationAmountCents,
                        'status' => 'confirmed',
                        'volunteer_interest' => Tiers::volunteerChain($tier),
                        'lgl_invitation_id' => null,
                        'is_new_constituent' => $newConstituent,
                    ]);
                    if ($registrationId < 1) throw new \RuntimeException('Registration creation failed.');
                }

                if ($registrationId > 0) {
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->prefix . 'bg_registrations',
                        ['newcomer_eligible' => $newcomerEligible ? 1 : 0],
                        ['id' => $registrationId],
                        ['%d'],
                        ['%d']
                    );
                }

                $existingForRegistration = $this->app->intakeByRegistration($registrationId);
                if ($existingForRegistration) {
                    $intakeId = (int) $existingForRegistration->id;
                    $matchStatus = 'existing_registration';
                    $reviewStatus = (string) $existingForRegistration->review_status;
                } else {
                    $intakeId = $this->app->insertIntake([
                        'event_post_id' => $eventId,
                        'registration_id' => $registrationId,
                        'constituent_id' => $constituentId,
                        'organizer_user_id' => $userId,
                        'intake_type' => 'walk_in',
                        'match_status' => $matchStatus,
                        'review_status' => $reviewStatus,
                        'review_reason' => $reviewStatus === 'pending' ? 'ambiguous_match' : '',
                        'request_hash' => $requestHash,
                        'idempotency_key' => $key,
                    ]);
                    if ($intakeId < 1) throw new \RuntimeException('Intake record creation failed.');
                }

                $attendance = $this->app->updateAttendance($eventId, $registrationId, 'checked_in', $userId, '');
                if (!$attendance['ok'] && !$attendance['conflict']) throw new \RuntimeException('Check-in failed.');
                if (!$this->constituents->commitAtomic()) throw new \RuntimeException('The walk-in transaction could not be committed.');
            } catch (\Throwable $e) {
                $this->constituents->rollbackAtomic();
                $this->logger->error('organizer_app', 'Walk-in registration failed.', [
                    'event' => $eventId,
                    'organizer' => $userId,
                    'reason' => $e->getMessage(),
                ]);
                return $this->error('walkin_failed', OrganizerAppWording::text('organizer_app_api_walkin_failed', 'The walk-in could not be completed safely.'), 409);
            }

            if (!$this->regions->assign($constituentId)) {
                $this->logger->warn('organizer_app', 'Walk-in regional assignment could not be refreshed.', [
                    'event' => $eventId,
                    'constituent' => $constituentId,
                ]);
            }

            $this->portal->audit($userId, $eventId, $registrationId, 'walkin_registered', $matchStatus . ' intake #' . $intakeId);
            return [
                'idempotent' => false,
                'intake_id' => $intakeId,
                'registration_id' => $registrationId,
                'constituent_id' => $constituentId,
                'match_status' => $matchStatus,
                'review_required' => $reviewStatus === 'pending',
                'newcomer_eligible' => $newcomerEligible,
                'display_name' => trim($record['first_name'] . ' ' . $record['last_name']),
                'tier_amount_cents' => $registrationAmountCents,
                'state' => $this->eventStateForUser($userId, $eventId),
            ];
        });
    }

    /** @return array<string,mixed>|\WP_Error */
    public function issueAttendeeSession(int $userId, int $eventId, array $input): array|\WP_Error
    {
        if (!$this->access->canEvent($userId, $eventId, 'private_answers')) {
            return $this->error('forbidden_private', OrganizerAppWording::text('organizer_app_api_private_forbidden', 'You cannot open attendee privacy mode for this event.'), 403);
        }
        return $this->withEventLock($eventId, function () use ($userId, $eventId, $input): array|\WP_Error {
            if ($this->app->writeLocked($eventId)) {
                return $this->error('event_closed', OrganizerAppWording::text('organizer_app_api_event_closed', 'This event is locked by its submitted closeout.'), 409);
            }
            $registrationId = absint($input['registration_id'] ?? 0);
            $registration = $this->app->registration($eventId, $registrationId);
            if (!$registration || !in_array((string) $registration->status, ['confirmed', 'paid'], true)) {
                return $this->error('invalid_registration', OrganizerAppWording::text('organizer_app_api_registration_invalid', 'Attendee privacy mode requires an active event registration.'), 404);
            }
            $constituentId = (int) ($registration->bg_constituent_id ?? 0);
            if ($constituentId < 1) {
                return $this->error('invalid_registration', OrganizerAppWording::text('organizer_app_api_registration_invalid', 'Attendee privacy mode requires an active event registration.'), 404);
            }
            try {
                $token = bin2hex(random_bytes(32));
            } catch (\Throwable) {
                return $this->error('token_failed', OrganizerAppWording::text('organizer_app_api_token_failed', 'A secure handoff token could not be created.'), 500);
            }
            $expiresAt = gmdate('Y-m-d H:i:s', time() + self::SESSION_TTL);
            $sessionId = $this->app->issueSession(
                hash('sha256', $token),
                $eventId,
                $registrationId,
                $constituentId,
                $userId,
                $expiresAt
            );
            if ($sessionId < 1) return $this->error('session_failed', OrganizerAppWording::text('organizer_app_api_session_failed', 'Attendee privacy mode could not be opened.'), 500);
            $this->portal->audit($userId, $eventId, $registrationId, 'attendee_session_issued', 'Session #' . $sessionId);
            return ['token' => $token, 'expires_at' => $expiresAt];
        });
    }

    /** @return array<string,mixed>|\WP_Error */
    public function attendeeForm(string $token): array|\WP_Error
    {
        $session = $this->app->sessionByToken($token);
        if (!$session) return $this->error('invalid_session', OrganizerAppWording::text('organizer_app_api_session_invalid', 'This privacy session has expired or has already been used.'), 410);
        $groups = [];
        foreach ($this->groups->all() as $group) {
            $groups[] = [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'description' => (string) $group->description,
            ];
        }
        return [
            'event_title' => get_the_title((int) $session->event_post_id),
            'expires_at' => (string) $session->expires_at,
            'questions' => $this->questionFlags(),
            'age_ranges' => RegistrationConfig::enabled('age') ? SurveyFields::ageRanges() : [],
            'demographics' => RegistrationConfig::enabled('demographics') ? DemographicFields::options() : [],
            'heard_options' => RegistrationConfig::enabled('heard') ? SurveyFields::heardOptions() : [],
            'promote_options' => RegistrationConfig::enabled('promote') ? SurveyFields::promoteOptions() : [],
            'relationship_options' => RegistrationConfig::enabled('relationship') ? SurveyFields::relationshipOptions() : [],
            'groups' => $groups,
            'monthly_options' => $this->monthlyOptions(),
            'monthly_url' => MonthlyDonationEnrollment::url(),
        ];
    }

    /** @return array<string,mixed>|\WP_Error */
    public function submitAttendee(string $token, array $input): array|\WP_Error
    {
        $initial = $this->app->sessionByToken($token);
        if (!$initial) return $this->error('invalid_session', OrganizerAppWording::text('organizer_app_api_session_invalid', 'This privacy session has expired or has already been used.'), 410);
        $eventId = (int) $initial->event_post_id;

        return $this->withEventLock($eventId, function () use ($token, $input, $eventId): array|\WP_Error {
            $session = $this->app->sessionByToken($token);
            if (!$session) return $this->error('invalid_session', OrganizerAppWording::text('organizer_app_api_session_invalid', 'This privacy session has expired or has already been used.'), 410);
            if ($this->app->writeLocked($eventId)) {
                return $this->error('event_closed', OrganizerAppWording::text('organizer_app_api_private_event_closed', 'This event was closed before the private form was submitted. Staff must reopen it before these answers can be saved.'), 409);
            }

            $age = RegistrationConfig::enabled('age')
                ? SurveyFields::sanitizeAge(sanitize_text_field((string) ($input['age_range'] ?? '')))
                : '';
            $demographics = RegistrationConfig::enabled('demographics') ? DemographicFields::sanitize(array_map(
                static fn($v): string => sanitize_text_field((string) $v),
                (array) ($input['demographics'] ?? [])
            )) : [];
            if ($age === '' && RegistrationConfig::required('age')) return $this->error('age_required', OrganizerAppWording::text('organizer_app_api_age_required', 'Choose an age range or Prefer not to say.'), 400);
            if (!$demographics && RegistrationConfig::required('demographics')) return $this->error('demographics_required', OrganizerAppWording::text('organizer_app_api_demographics_required', 'Choose at least one demographic response or Prefer not to say.'), 400);
            $other = in_array(DemographicFields::OTHER_KEY, $demographics, true)
                ? bg_substr(sanitize_text_field((string) ($input['demographics_other'] ?? '')), 0, 190)
                : '';
            $heard = RegistrationConfig::enabled('heard') ? SurveyFields::sanitizeHeard(array_map(
                static fn($v): string => sanitize_text_field((string) $v),
                (array) ($input['heard_about'] ?? [])
            )) : [];
            if (!$heard && RegistrationConfig::required('heard')) return $this->error('heard_required', OrganizerAppWording::text('organizer_app_api_heard_required', 'Choose at least one response for how you heard about the event.'), 400);
            $heardOther = in_array(SurveyFields::HEARD_OTHER, $heard, true)
                ? bg_substr(sanitize_text_field((string) ($input['heard_other'] ?? '')), 0, 190)
                : '';
            $promote = RegistrationConfig::enabled('promote')
                ? SurveyFields::sanitizePromote(sanitize_text_field((string) ($input['promote'] ?? '')))
                : '';
            if ($promote === '' && RegistrationConfig::required('promote')) return $this->error('promote_required', OrganizerAppWording::text('organizer_app_api_promote_required', 'Please answer the question about spreading the word.'), 400);
            $relationship = RegistrationConfig::enabled('relationship') ? SurveyFields::sanitizeRelationship(array_map(
                static fn($v): string => sanitize_text_field((string) $v),
                (array) ($input['relationship'] ?? [])
            )) : [];
            if (!$relationship && RegistrationConfig::required('relationship')) return $this->error('relationship_required', OrganizerAppWording::text('organizer_app_api_relationship_required', 'Please answer the relationship question.'), 400);
            $sharing = sanitize_key((string) ($input['contact_sharing'] ?? ''));
            if (!in_array($sharing, ['allow', 'withhold'], true)) {
                return $this->error('consent_required', OrganizerAppWording::text('organizer_app_api_consent_required', 'Choose whether event organizers may view contact information.'), 400);
            }
            $groupIds = array_values(array_unique(array_filter(array_map('absint', (array) ($input['group_ids'] ?? [])))));
            $monthlyInterest = (int) ($input['monthly_interest_cents'] ?? 0);
            if (!in_array($monthlyInterest, array_column($this->monthlyOptions(), 'amount'), true)) $monthlyInterest = 0;
            $constituentId = (int) $session->constituent_id;

            if (!$this->constituents->beginAtomic()) {
                return $this->error('transaction_failed', OrganizerAppWording::text('organizer_app_api_private_transaction', 'Your answers could not be saved safely.'), 500);
            }
            try {
                if (!$this->constituents->setPersonalAnswers($constituentId, [
                    'age_range' => $age,
                    'demographics' => $demographics,
                    'demographics_other' => $other,
                    'heard' => $heard,
                    'heard_other' => $heardOther,
                    'promote' => $promote,
                    'relationship' => $relationship,
                ])) throw new \RuntimeException('The encrypted registration questions could not be saved.');
                // The organizer holds this device, so an earlier "withhold"
                // is never reversed here; the person can change it on My Profile.
                $previousSharing = $this->constituents->latestConsent($constituentId, ConstituentStore::CONTACT_SHARING_PURPOSE);
                $grantSharing = $sharing === 'allow' && $previousSharing !== false;
                if ($previousSharing !== $grantSharing && !$this->constituents->recordConsent(
                    $constituentId,
                    ConstituentStore::CONTACT_SHARING_PURPOSE,
                    $grantSharing,
                    'organizer_app_attendee'
                )) throw new \RuntimeException('The contact-sharing choice could not be saved.');
                foreach ($groupIds as $groupId) {
                    if (!$this->groups->byId($groupId)) continue;
                    if (!$this->groups->subscribe($constituentId, $groupId, 'organizer_app_attendee')) {
                        throw new \RuntimeException('A selected mailing-list subscription could not be saved.');
                    }
                }
                if (!$this->app->recordPrivateCompletion(
                    $eventId,
                    (int) $session->registration_id,
                    $constituentId,
                    (int) $session->issued_by,
                    $monthlyInterest,
                    true,
                    true
                )) throw new \RuntimeException('The intake completion record could not be saved.');

                $effectiveIntake = $this->app->intakeByRegistration((int) $session->registration_id);
                if ($effectiveIntake) {
                    $monthlyInterest = max(0, (int) ($effectiveIntake->monthly_interest_cents ?? 0));
                }
                if (!$this->app->markSessionUsed((int) $session->id)) {
                    throw new \RuntimeException('This privacy session was already completed on another request.');
                }
                if (!$this->constituents->commitAtomic()) throw new \RuntimeException('The privacy submission could not be committed.');
            } catch (\Throwable $e) {
                $this->constituents->rollbackAtomic();
                $this->logger->error('organizer_app', 'Attendee privacy submission failed.', [
                    'session' => (int) $session->id,
                    'reason' => $e->getMessage(),
                ]);
                return $this->error('private_save_failed', OrganizerAppWording::text('organizer_app_api_private_failed', 'Your answers could not be completed safely.'), 409);
            }
            $this->portal->audit(
                (int) $session->issued_by,
                $eventId,
                (int) $session->registration_id,
                'attendee_answers_completed',
                $monthlyInterest > 0
                    ? 'Private attendee submission completed; monthly follow-up ' . number_format($monthlyInterest / 100, 2)
                    : 'Private attendee submission completed'
            );
            return [
                'message' => OrganizerAppWording::text('organizer_app_api_private_saved', 'Your private answers and choices were saved.'),
                'monthly_interest_cents' => $monthlyInterest,
                'monthly_url' => MonthlyDonationEnrollment::url(),
            ];
        });
    }

    /** @return array<string,mixed>|\WP_Error */
    public function recordTender(int $userId, int $eventId, array $input): array|\WP_Error
    {
        if (!$this->access->canEvent($userId, $eventId, 'tender')) {
            return $this->error('forbidden_tender', OrganizerAppWording::text('organizer_app_api_tender_forbidden', 'You cannot record payments for this event.'), 403);
        }
        return $this->withEventLock($eventId, function () use ($userId, $eventId, $input): array|\WP_Error {
            if ($this->app->writeLocked($eventId)) {
                return $this->error('event_closed', OrganizerAppWording::text('organizer_app_api_event_closed', 'This event is locked by its submitted closeout.'), 409);
            }
            $registrationId = absint($input['registration_id'] ?? 0);
            $registration = $this->app->registration($eventId, $registrationId);
            if (!$registration || !in_array((string) $registration->status, ['confirmed', 'paid'], true)) {
                return $this->error('registration_required', OrganizerAppWording::text('organizer_app_api_registration_required', 'Choose an active attendee from this event before recording a payment.'), 400);
            }
            $constituentId = (int) ($registration->bg_constituent_id ?? 0);
            $method = sanitize_key((string) ($input['method'] ?? ''));
            if (!in_array($method, ['cash', 'check'], true)) return $this->error('invalid_method', OrganizerAppWording::text('organizer_app_api_method_invalid', 'Choose cash or check.'), 400);
            $checkReference = $method === 'check'
                ? bg_substr(sanitize_text_field((string) ($input['check_reference'] ?? '')), 0, 80)
                : '';
            $clientKey = $this->clientKey($input['idempotency_key'] ?? '');
            if ($clientKey === '') {
                return $this->error('missing_idempotency', OrganizerAppWording::text('organizer_app_api_retry_refresh', 'The app could not establish a safe retry key. Refresh and try again.'), 400);
            }
            $requestKey = hash('sha256', 'tender|' . $eventId . '|' . $userId . '|' . $clientKey);

            $lines = [];
            $expected = [];
            foreach (['event_fee', 'donation'] as $purpose) {
                $rawAmount = (int) ($input[$purpose . '_cents'] ?? 0);
                $amount = $this->money($rawAmount);
                if ($amount < 0) {
                    return $this->error('invalid_amount', OrganizerAppWording::format(
                        'organizer_app_api_amount_invalid',
                        'Each payment line must be between {minimum} and {maximum}.',
                        ['minimum' => \BosqueGrande\Core\Money::format(1), 'maximum' => \BosqueGrande\Core\Money::format(self::MAX_MONEY_CENTS)]
                    ), 400);
                }
                if ($amount > 0) {
                    $lines[] = ['purpose' => $purpose, 'amount_cents' => $amount];
                    $expected[$purpose] = $amount;
                }
            }
            if (!$lines) return $this->error('amount_required', OrganizerAppWording::text('organizer_app_api_amount_required', 'Enter a registration payment, a donation, or both.'), 400);

            $requestHash = hash('sha256', implode('|', [
                (string) $eventId,
                (string) $registrationId,
                (string) $userId,
                $method,
                (string) ($expected['event_fee'] ?? 0),
                (string) ($expected['donation'] ?? 0),
                hash('sha256', $checkReference),
            ]));
            $existing = $this->app->tendersByOperation($requestKey);
            $operationError = $this->tenderOperationError(
                $existing,
                $eventId,
                $registrationId,
                $userId,
                $method,
                $checkReference,
                $requestHash,
                $expected
            );
            if ($operationError) return $operationError;
            if (count($existing) === count($lines)) {
                return $this->receipt($eventId, $registrationId, $method, $this->orderTenderRows($existing, $lines), true);
            }

            if (!$this->constituents->beginAtomic()) {
                return $this->error('transaction_failed', OrganizerAppWording::text('organizer_app_api_payment_transaction', 'The payment could not be recorded safely.'), 500);
            }
            $created = [];
            try {
                foreach ($lines as $line) {
                    $lineKey = hash('sha256', $requestKey . '|' . $line['purpose']);
                    $found = $this->app->tenderByKey($lineKey);
                    if ($found) {
                        $error = $this->tenderOperationError(
                            [$found],
                            $eventId,
                            $registrationId,
                            $userId,
                            $method,
                            $checkReference,
                            $requestHash,
                            [$line['purpose'] => $line['amount_cents']]
                        );
                        if ($error) throw new \RuntimeException($error->get_error_message());
                        $created[] = $found;
                        continue;
                    }

                    $externalKey = 'organizer-app:' . $lineKey;
                    $paymentId = $this->ledger->record([
                        'kind' => $line['purpose'],
                        'amount_cents' => $line['amount_cents'],
                        'constituent_id' => $constituentId,
                        'registration_id' => $registrationId,
                        'external_key' => $externalKey,
                        'source' => 'organizer_app',
                        'note' => ($method === 'cash' ? 'Cash' : 'Check') . ' received for event #' . $eventId,
                    ]);
                    if ($paymentId < 1) {
                        $paymentId = $this->ledger->idByExternalKey($externalKey);
                        if ($paymentId < 1) throw new \RuntimeException('The BosqueGrande payment ledger refused the transaction.');
                    }
                    $tenderId = $this->app->createTender([
                        'event_post_id' => $eventId,
                        'registration_id' => $registrationId,
                        'constituent_id' => $constituentId,
                        'organizer_user_id' => $userId,
                        'payment_id' => $paymentId,
                        'operation_id' => $requestKey,
                        'request_hash' => $requestHash,
                        'purpose' => $line['purpose'],
                        'method' => $method,
                        'amount_cents' => $line['amount_cents'],
                        'idempotency_key' => $lineKey,
                    ], $checkReference);
                    if ($tenderId < 1) {
                        $concurrent = $this->app->tenderByKey($lineKey);
                        $error = $concurrent ? $this->tenderOperationError(
                            [$concurrent],
                            $eventId,
                            $registrationId,
                            $userId,
                            $method,
                            $checkReference,
                            $requestHash,
                            [$line['purpose'] => $line['amount_cents']]
                        ) : null;
                        if (!$concurrent || $error) {
                            throw new \RuntimeException($error?->get_error_message() ?: 'The cash/check detail record could not be saved.');
                        }
                        $created[] = $concurrent;
                        continue;
                    }
                    $row = $this->app->tender($tenderId);
                    if (!$row) throw new \RuntimeException('The saved payment could not be verified.');
                    $created[] = $row;
                }
                if (!$this->constituents->commitAtomic()) throw new \RuntimeException('The payment transaction could not be committed.');
            } catch (\Throwable $e) {
                $this->constituents->rollbackAtomic();
                $this->logger->error('organizer_app', 'Manual tender failed.', [
                    'event' => $eventId,
                    'registration' => $registrationId,
                    'reason' => $e->getMessage(),
                ]);
                return $this->error('tender_failed', OrganizerAppWording::text('organizer_app_api_payment_failed', 'The payment could not be completed safely.'), 409);
            }
            $created = $this->orderTenderRows($created, $lines);
            $total = array_sum(array_map(static fn(object $row): int => (int) $row->amount_cents, $created));
            $this->portal->audit($userId, $eventId, $registrationId, 'tender_recorded', $method . ' ' . \BosqueGrande\Core\Money::format((int) $total));
            return $this->receipt($eventId, $registrationId, $method, $created, false);
        });
    }

    /** @return array<string,mixed>|\WP_Error */
    public function closeout(int $userId, int $eventId): array|\WP_Error
    {
        if (!$this->access->canEvent($userId, $eventId, 'close_out')) {
            return $this->error('forbidden_closeout', OrganizerAppWording::text('organizer_app_api_closeout_forbidden', 'You cannot close out this event.'), 403);
        }
        return $this->withEventLock(
            $eventId,
            fn(): array => $this->closeoutPayload($eventId)
        );
    }

    /** @return array<string,mixed>|\WP_Error */
    public function submitCloseout(int $userId, int $eventId, array $input): array|\WP_Error
    {
        if (!$this->access->canEvent($userId, $eventId, 'close_out')) {
            return $this->error('forbidden_closeout', OrganizerAppWording::text('organizer_app_api_closeout_forbidden', 'You cannot close out this event.'), 403);
        }
        return $this->withEventLock($eventId, function () use ($userId, $eventId, $input): array|\WP_Error {
            $existing = $this->app->closeout($eventId);
            $clientKey = $this->clientKey($input['idempotency_key'] ?? '');
            if ($clientKey === '') return $this->error('missing_idempotency', OrganizerAppWording::text('organizer_app_api_retry_missing', 'The app could not establish a safe retry key.'), 400);
            $submissionKey = hash('sha256', 'closeout|' . $eventId . '|' . $userId . '|' . $clientKey);
            if ($existing && (string) ($existing->submission_key ?? '') === $submissionKey
                && in_array((string) $existing->status, OrganizerAppRepository::CLOSEOUT_LOCKED, true)
            ) return $this->closeoutPayload($eventId);
            if ($existing && in_array((string) $existing->status, OrganizerAppRepository::CLOSEOUT_LOCKED, true)) {
                return $this->error(
                    'closeout_locked',
                    (string) $existing->status === 'accepted'
                        ? OrganizerAppWording::text('organizer_app_api_closeout_accepted', 'Staff accepted this closeout. Staff must reopen it before changes can be made.')
                        : OrganizerAppWording::text('organizer_app_api_closeout_submitted', 'This closeout was already submitted. Staff must reopen it before a replacement can be submitted.'),
                    409
                );
            }

            $expectedRevision = strtolower(trim((string) ($input['expected_revision'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $expectedRevision) !== 1) {
                return $this->error('missing_revision', OrganizerAppWording::text('organizer_app_api_closeout_refresh', 'Refresh the closeout before submitting it.'), 409);
            }
            $snapshotJson = wp_json_encode($this->app->snapshot($eventId));
            if (!is_string($snapshotJson)) return $this->error('snapshot_failed', OrganizerAppWording::text('organizer_app_api_snapshot_failed', 'The event snapshot could not be generated.'), 500);
            $currentRevision = hash('sha256', $snapshotJson);
            if (!hash_equals($currentRevision, $expectedRevision)) {
                return $this->error('closeout_stale', OrganizerAppWording::text('organizer_app_api_closeout_stale', 'Attendance, intake, or payment records changed after this closeout was opened. Refresh and recount before submitting.'), 409);
            }
            $confirmed = $input['confirm_complete'] ?? false;
            if (!in_array($confirmed, [true, 1, '1', 'true'], true)) {
                return $this->error('closeout_confirmation_required', OrganizerAppWording::text('organizer_app_api_closeout_confirm', 'Confirm that attendance and money entries are complete before submitting.'), 400);
            }

            $totals = $this->app->moneyTotals($eventId);
            $countedCash = $this->money((int) ($input['counted_cash_cents'] ?? -1), true);
            $countedCheck = $this->money((int) ($input['counted_check_cents'] ?? -1), true);
            $rawCheckCount = $input['check_count'] ?? 0;
            $checkCount = filter_var($rawCheckCount, FILTER_VALIDATE_INT);
            if ($countedCash < 0 || $countedCheck < 0 || $checkCount === false || $checkCount < 0 || $checkCount > 10000) {
                return $this->error('invalid_count', OrganizerAppWording::text('organizer_app_api_count_invalid', 'Enter valid counted cash, counted checks, and physical check count.'), 400);
            }
            $cashVariance = $countedCash - $totals['cash_cents'];
            $checkVariance = $countedCheck - $totals['check_cents'];
            $explanation = bg_substr(sanitize_textarea_field((string) ($input['explanation'] ?? '')), 0, 2000);
            if (($cashVariance !== 0 || $checkVariance !== 0) && trim($explanation) === '') {
                return $this->error('variance_explanation_required', OrganizerAppWording::text('organizer_app_api_variance_required', 'Explain any cash or check variance before submitting.'), 400);
            }

            if (!$this->constituents->beginAtomic()) {
                return $this->error('transaction_failed', OrganizerAppWording::text('organizer_app_api_closeout_transaction', 'The closeout could not be submitted safely.'), 500);
            }
            try {
                $closeoutId = $this->app->saveCloseout([
                    'event_post_id' => $eventId,
                    'organizer_user_id' => $userId,
                    'expected_cash_cents' => $totals['cash_cents'],
                    'expected_check_cents' => $totals['check_cents'],
                    'counted_cash_cents' => $countedCash,
                    'counted_check_cents' => $countedCheck,
                    'check_count' => $checkCount,
                    'cash_variance_cents' => $cashVariance,
                    'check_variance_cents' => $checkVariance,
                    'snapshot_hash' => $currentRevision,
                    'submission_key' => $submissionKey,
                ], $explanation);
                if ($closeoutId < 1) throw new \RuntimeException('The event closeout could not be saved.');
                if (!$this->app->markEventTendersReconciled($eventId, $closeoutId)) {
                    throw new \RuntimeException('The event payment records could not be linked to the closeout.');
                }
                if (!$this->constituents->commitAtomic()) throw new \RuntimeException('The closeout transaction could not be committed.');
            } catch (\Throwable $e) {
                $this->constituents->rollbackAtomic();
                $this->logger->error('organizer_app', 'Event closeout failed.', [
                    'event' => $eventId,
                    'organizer' => $userId,
                    'reason' => $e->getMessage(),
                ]);
                return $this->error('closeout_failed', OrganizerAppWording::text('organizer_app_api_closeout_failed', 'The closeout could not be completed safely.'), 409);
            }
            $this->portal->audit($userId, $eventId, 0, 'event_closeout_submitted', 'Closeout #' . $closeoutId);
            return $this->closeoutPayload($eventId);
        });
    }

    /** @return bool|\WP_Error */
    public function reviewCloseout(int $userId, int $closeoutId, string $decision, string $reason = ''): bool|\WP_Error
    {
        if (!user_can($userId, 'manage_options')) return $this->error('forbidden_staff', OrganizerAppWording::text('organizer_app_api_staff_required', 'Staff access is required.'), 403);
        if (!in_array($decision, ['accept', 'reopen'], true)) return $this->error('invalid_decision', OrganizerAppWording::text('organizer_app_api_decision_invalid', 'Choose accept or reopen.'), 400);
        $row = $this->app->closeoutById($closeoutId);
        if (!$row) return $this->error('missing_closeout', OrganizerAppWording::text('organizer_app_api_closeout_missing', 'The closeout could not be found.'), 404);
        $eventId = (int) $row->event_post_id;
        $reason = bg_substr(sanitize_textarea_field($reason), 0, 180);
        if ($decision === 'reopen' && trim($reason) === '') {
            return $this->error('reason_required', OrganizerAppWording::text('organizer_app_api_reopen_reason', 'A reopening reason is required.'), 400);
        }

        return $this->withEventLock($eventId, function () use ($userId, $closeoutId, $decision, $reason, $eventId): bool|\WP_Error {
            if (!$this->constituents->beginAtomic()) {
                return $this->error('transaction_failed', OrganizerAppWording::text('organizer_app_api_review_transaction', 'The closeout review could not be started safely.'), 500);
            }
            try {
                if (!$this->app->reviewCloseout($closeoutId, $decision, $userId)) {
                    throw new \RuntimeException('The closeout status changed before the review could be saved.');
                }
                if ($decision === 'reopen' && !$this->app->markEventTendersRecorded($eventId)) {
                    throw new \RuntimeException('The event payment records could not be reopened.');
                }
                if (!$this->constituents->commitAtomic()) throw new \RuntimeException('The closeout review could not be committed.');
            } catch (\Throwable $e) {
                $this->constituents->rollbackAtomic();
                return $this->error('closeout_review_failed', OrganizerAppWording::text('organizer_app_api_review_failed', 'The closeout review could not be completed safely.'), 409);
            }
            $detail = 'Closeout #' . $closeoutId;
            if ($decision === 'reopen') $detail .= '; reason: ' . $reason;
            $this->portal->audit($userId, $eventId, 0, 'closeout_' . $decision, $detail);
            return true;
        });
    }

    /** @return bool|\WP_Error */
    public function voidTender(int $userId, int $tenderId, string $reason): bool|\WP_Error
    {
        if (!user_can($userId, 'manage_options')) return $this->error('forbidden_staff', OrganizerAppWording::text('organizer_app_api_staff_required', 'Staff access is required.'), 403);
        $initial = $this->app->tender($tenderId);
        if (!$initial) return $this->error('invalid_tender', OrganizerAppWording::text('organizer_app_api_tender_missing', 'The payment record could not be found.'), 404);
        $eventId = (int) $initial->event_post_id;

        return $this->withEventLock($eventId, function () use ($userId, $tenderId, $reason, $eventId): bool|\WP_Error {
            $tender = $this->app->tender($tenderId);
            if (!$tender || (string) $tender->status !== 'recorded') {
                return $this->error('invalid_tender', OrganizerAppWording::text('organizer_app_api_tender_not_voidable', 'Only a recorded payment from a reopened or open event can be voided.'), 409);
            }
            if ($this->app->writeLocked($eventId)) {
                return $this->error('event_closed', OrganizerAppWording::text('organizer_app_api_void_reopen', 'Reopen the event closeout before voiding this payment.'), 409);
            }
            $cleanReason = bg_substr(sanitize_textarea_field($reason), 0, 1000);
            if ($cleanReason === '') return $this->error('reason_required', OrganizerAppWording::text('organizer_app_api_correction_reason', 'A correction reason is required.'), 400);
            if (!$this->constituents->beginAtomic()) {
                return $this->error('transaction_failed', OrganizerAppWording::text('organizer_app_api_correction_transaction', 'The correction could not be started safely.'), 500);
            }
            try {
                $refundId = $this->ledger->ensureRefund((int) $tender->payment_id, 'organizer_app_void');
                if ($refundId < 1) throw new \RuntimeException('The compensating ledger row could not be created.');
                if (!$this->app->markTenderVoided($tenderId, $refundId, $userId, $cleanReason)) {
                    throw new \RuntimeException('The tender could not be marked void.');
                }
                if (!$this->constituents->commitAtomic()) throw new \RuntimeException('The correction could not be committed.');
            } catch (\Throwable $e) {
                $this->constituents->rollbackAtomic();
                return $this->error('void_failed', OrganizerAppWording::text('organizer_app_api_void_failed', 'The correction could not be completed safely.'), 409);
            }
            $this->portal->audit($userId, $eventId, (int) $tender->registration_id, 'tender_voided', 'Tender #' . $tenderId);
            return true;
        });
    }

    /** @return array<string,mixed> */
    private function closeoutPayload(int $eventId): array
    {
        $totals = $this->app->moneyTotals($eventId);
        $state = $this->app->eventState($eventId);
        $row = $this->app->closeout($eventId);
        $snapshotJson = wp_json_encode($this->app->snapshot($eventId));
        $revision = hash('sha256', is_string($snapshotJson) ? $snapshotJson : 'snapshot-error');
        return [
            'expected_cash_cents' => $totals['cash_cents'],
            'expected_check_cents' => $totals['check_cents'],
            'tender_count' => $totals['tender_count'],
            'registration_payment_cents' => $totals['event_fee_cents'],
            'donation_cents' => $totals['donation_cents'],
            'registration_count' => (int) ($state['total'] ?? 0),
            'checked_in_count' => (int) ($state['checked_in'] ?? 0),
            'attended_count' => (int) ($state['attended'] ?? 0),
            'walk_in_count' => (int) ($state['walk_ins'] ?? 0),
            'scholarship_count' => (int) ($state['scholarship'] ?? 0),
            'unresolved_records' => (int) ($state['unresolved_records'] ?? 0),
            'status' => $row ? (string) $row->status : 'open',
            'counted_cash_cents' => $row ? (int) $row->counted_cash_cents : $totals['cash_cents'],
            'counted_check_cents' => $row ? (int) $row->counted_check_cents : $totals['check_cents'],
            'check_count' => $row ? (int) ($row->check_count ?? 0) : 0,
            'cash_variance_cents' => $row ? (int) $row->cash_variance_cents : 0,
            'check_variance_cents' => $row ? (int) $row->check_variance_cents : 0,
            'revision' => $revision,
            'write_locked' => $this->app->writeLocked($eventId),
        ];
    }

    /** @return mixed */
    private function withEventLock(int $eventId, callable $callback): mixed
    {
        if (!$this->app->acquireEventLock($eventId, 3)) {
            return $this->error('event_busy', OrganizerAppWording::text('organizer_app_api_event_busy', 'Another organizer action is being saved for this event. Try this action again.'), 409);
        }
        try {
            return $callback();
        } finally {
            $this->app->releaseEventLock($eventId);
        }
    }

    /**
     * @param list<object> $rows
     * @param array<string,int> $expected
     */
    private function tenderOperationError(
        array $rows,
        int $eventId,
        int $registrationId,
        int $userId,
        string $method,
        string $checkReference,
        string $requestHash,
        array $expected
    ): ?\WP_Error {
        if (count($rows) > count($expected)) {
            return $this->error('idempotency_conflict', OrganizerAppWording::text('organizer_app_api_payment_retry_lines', 'This payment retry key already contains a different set of payment lines.'), 409);
        }
        $seen = [];
        foreach ($rows as $row) {
            $purpose = (string) ($row->purpose ?? '');
            if (isset($seen[$purpose])
                || !array_key_exists($purpose, $expected)
                || (int) ($row->event_post_id ?? 0) !== $eventId
                || (int) ($row->registration_id ?? 0) !== $registrationId
                || (int) ($row->organizer_user_id ?? 0) !== $userId
                || (string) ($row->method ?? '') !== $method
                || (int) ($row->amount_cents ?? -1) !== $expected[$purpose]
                || !hash_equals((string) ($row->request_hash ?? ''), $requestHash)
                || !in_array((string) ($row->status ?? ''), OrganizerAppRepository::TENDER_ACTIVE, true)
                || !hash_equals($this->app->tenderCheckReference($row), $checkReference)
            ) {
                return $this->error('idempotency_conflict', OrganizerAppWording::text('organizer_app_api_payment_retry_details', 'This payment retry key was already used for different payment details. Refresh the payment form before recording another payment.'), 409);
            }
            $seen[$purpose] = true;
        }
        return null;
    }

    /** @param list<object> $rows @param list<array{purpose:string,amount_cents:int}> $lines @return list<object> */
    private function orderTenderRows(array $rows, array $lines): array
    {
        $byPurpose = [];
        foreach ($rows as $row) $byPurpose[(string) ($row->purpose ?? '')] = $row;
        $ordered = [];
        foreach ($lines as $line) {
            if (isset($byPurpose[$line['purpose']])) $ordered[] = $byPurpose[$line['purpose']];
        }
        return $ordered;
    }

    /** @return array<string,int|string|bool> */
    private function eventStateForUser(int $userId, int $eventId): array
    {
        $state = $this->app->eventState($eventId);
        $financial = $this->access->canEvent($userId, $eventId, 'tender')
            || $this->access->canEvent($userId, $eventId, 'close_out');
        if (!$financial) {
            unset($state['cash_cents'], $state['check_cents'], $state['tender_count']);
        }
        return $state;
    }

    /** @return array<string,mixed> */
    private function eventPayload(\WP_Post $event, int $userId, bool $includeState): array
    {
        $eventId = (int) $event->ID;
        $regionSlug = RegionalGroups::eventRegion(
            get_the_title($event),
            (string) get_post_meta($eventId, '_bg_location_text', true)
        );
        $assignment = $this->portal->assignment($userId, $eventId);
        $payload = [
            'id' => $eventId,
            'title' => get_the_title($event),
            'start_date' => (string) get_post_meta($eventId, '_bg_start_date', true),
            'start_time' => (string) get_post_meta($eventId, '_bg_start_time', true),
            'end_time' => function_exists('bgev_end_time') ? (string) \bgev_end_time($eventId) : (string) get_post_meta($eventId, '_bg_end_time', true),
            'end_date' => (string) get_post_meta($eventId, '_bg_end_date', true),
            'tiers' => $this->tierOptions($eventId),
            'location_region' => $regionSlug,
            'location_region_label' => RegionalGroups::label($regionSlug),
            'organizer_role' => $assignment
                ? (string) ($assignment->role_label ?? OrganizerAppWording::text('organizer_app_role_fallback', 'organizer'))
                : (user_can($userId, 'manage_options')
                    ? OrganizerAppWording::text('organizer_app_role_staff', 'staff')
                    : OrganizerAppWording::text('organizer_app_role_fallback', 'organizer')),
            'permissions' => [
                'view_roster' => $this->access->canEvent($userId, $eventId, 'roster'),
                'check_in' => $this->access->canEvent($userId, $eventId, 'check_in'),
                'walk_in' => $this->access->canEvent($userId, $eventId, 'walk_in'),
                'tender' => $this->access->canEvent($userId, $eventId, 'tender'),
                'private_answers' => $this->access->canEvent($userId, $eventId, 'private_answers'),
                'close_out' => $this->access->canEvent($userId, $eventId, 'close_out'),
            ],
        ];
        if ($includeState) $payload['state'] = $this->eventStateForUser($userId, $eventId);
        return $payload;
    }

    /** @return array<string, array{enabled: bool, required: bool}> */
    private function questionFlags(): array
    {
        $out = [];
        foreach (RegistrationConfig::QUESTIONS as $key) {
            $out[$key] = ['enabled' => RegistrationConfig::enabled($key), 'required' => RegistrationConfig::required($key)];
        }
        return $out;
    }

    /** @return list<array{value:string,label:string,amount_cents:int,gated:bool}> */
    private function tierOptions(int $eventId = 0): array
    {
        $out = [];
        foreach (($eventId > 0 ? Tiers::forEvent($eventId) : Tiers::all()) as $value => $tier) {
            $out[] = [
                'value' => $value,
                'label' => (string) $tier['label'],
                'amount_cents' => (int) $tier['amount_cents'],
                'gated' => (bool) $tier['gated'],
            ];
        }
        return $out;
    }

    /** @return array<string,string> */
    private function cleanWalkIn(array $input): array
    {
        $state = strtoupper(bg_substr(sanitize_text_field((string) ($input['state'] ?? '')), 0, 40));
        return [
            'first_name' => bg_substr(sanitize_text_field((string) ($input['first_name'] ?? '')), 0, 100),
            'last_name' => bg_substr(sanitize_text_field((string) ($input['last_name'] ?? '')), 0, 100),
            'email' => strtolower(sanitize_email((string) ($input['email'] ?? ''))),
            'phone' => bg_substr(sanitize_text_field((string) ($input['phone'] ?? '')), 0, 50),
            'address1' => bg_substr(sanitize_text_field((string) ($input['street'] ?? $input['address1'] ?? '')), 0, 190),
            'address2' => bg_substr(sanitize_text_field((string) ($input['address2'] ?? '')), 0, 190),
            'city' => bg_substr(sanitize_text_field((string) ($input['city'] ?? '')), 0, 100),
            'state' => $state,
            'postal' => bg_substr(sanitize_text_field((string) ($input['zip'] ?? $input['postal'] ?? '')), 0, 20),
            'notes' => '',
        ];
    }

    /** @return list<array{key:string,amount:int,label:string}> */
    private function monthlyOptions(): array
    {
        return array_merge([
            ['key' => 'none', 'amount' => 0, 'label' => OrganizerAppWording::text('organizer_app_no_monthly_followup', 'No monthly-giving follow-up')],
        ], MonthlyDonationEnrollment::organizerOptions());
    }

    private function clientKey(mixed $raw): string
    {
        $key = trim((string) $raw);
        return preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key) === 1 ? $key : '';
    }

    private function money(int $cents, bool $allowZero = false): int
    {
        if ($cents < 0 || $cents > self::MAX_MONEY_CENTS) return -1;
        if (!$allowZero && $cents === 0) return 0;
        return $cents;
    }

    /** @param list<object> $rows @return array<string,mixed> */
    private function receipt(int $eventId, int $registrationId, string $method, array $rows, bool $idempotent): array
    {
        $lines = [];
        $total = 0;
        $recordedAt = '';
        foreach ($rows as $row) {
            $amount = (int) $row->amount_cents;
            $total += $amount;
            $createdAt = (string) ($row->created_at ?? '');
            if ($createdAt !== '' && ($recordedAt === '' || strcmp($createdAt, $recordedAt) < 0)) {
                $recordedAt = $createdAt;
            }
            $lines[] = [
                'tender_id' => (int) $row->id,
                'payment_id' => (int) $row->payment_id,
                'purpose' => (string) $row->purpose,
                'amount_cents' => $amount,
            ];
        }
        return [
            'idempotent' => $idempotent,
            'receipt' => [
                'event_id' => $eventId,
                'registration_id' => $registrationId,
                'method' => $method,
                'lines' => $lines,
                'total_cents' => $total,
                'recorded_at' => $recordedAt !== '' ? $recordedAt : current_time('mysql', true),
            ],
            'state' => $this->app->eventState($eventId),
        ];
    }

    /** @return array<string,mixed> */
    private function publicTender(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'registration_id' => (int) $row->registration_id,
            'purpose' => (string) $row->purpose,
            'method' => (string) $row->method,
            'amount_cents' => (int) $row->amount_cents,
            'status' => (string) $row->status,
            'has_check_reference' => !empty($row->has_check_reference),
            'created_at' => (string) $row->created_at,
        ];
    }

    private function error(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error($code, $message, ['status' => $status]);
    }
}
