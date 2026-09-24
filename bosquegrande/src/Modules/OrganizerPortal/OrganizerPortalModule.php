<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\OrganizerPortal;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Exchange\Mapper;
use BosqueGrande\Modules\Locations\Taxonomy as LocationTaxonomy;

if (!defined('ABSPATH')) exit;

/** Complete, front-end event-organizer workspace with staff approval. */
final class OrganizerPortalModule implements ModuleInterface
{
    public const SHORTCODE = 'bg_organizer_portal';
    public const ADMIN_PAGE = 'bosquegrande-organizers';

    public function name(): string
    {
        return 'organizer_portal';
    }

    public function label(): string
    {
        return 'Event Organizer Portal';
    }

    public function activate(Container $container): void
    {
        OrganizerAccess::ensureRole();
    }

    public function deactivate(Container $container): void
    {
        // Accounts, assignments, and audit history survive deactivation.
    }

    public function boot(Container $container): void
    {
        add_filter('bg_wording_defaults', [PortalWording::class, 'defaults']);

        $container->set('organizers.repository', fn(Container $c) => new OrganizerRepository(
            $c->get('encryption'),
            $c->get('constituents.store'),
            $c->get('logger')
        ));
        $container->set('organizers.access', fn(Container $c) => new OrganizerAccess(
            $c->get('organizers.repository')
        ));
        $container->set('organizers.renderer', fn(Container $c) => new PortalRenderer(
            $c->get('organizers.repository'),
            $c->get('organizers.access')
        ));

        add_action('init', static function (): void {
            if ((string) get_option('bg_organizer_role_version', '') !== BG_VERSION) {
                OrganizerAccess::ensureRole();
                update_option('bg_organizer_role_version', BG_VERSION, false);
            }
        }, 40);

        add_action('wp_enqueue_scripts', static function (): void {
            wp_register_style(
                'bosquegrande-organizer-portal',
                BG_URL . 'src/Modules/OrganizerPortal/assets/organizer-portal.css',
                [],
                BG_VERSION
            );
            $post = get_post();
            if ($post instanceof \WP_Post && has_shortcode((string) $post->post_content, self::SHORTCODE)) {
                wp_enqueue_style('bosquegrande-organizer-portal');
            }
        });

        add_shortcode(self::SHORTCODE, function () use ($container): string {
            if (is_singular()) {
                $pageId = get_queried_object_id();
                if ($pageId > 0 && (int) get_option('bg_organizer_portal_page_id', 0) !== $pageId) {
                    update_option('bg_organizer_portal_page_id', $pageId, false);
                }
            }
            /** @var PortalRenderer $renderer */
            $renderer = $container->get('organizers.renderer');
            return $renderer->render();
        });

        add_action('template_redirect', static function (): void {
            if (is_admin() || !is_singular()) return;
            $post = get_post();
            if (!$post instanceof \WP_Post || !has_shortcode((string) $post->post_content, self::SHORTCODE)) return;
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            nocache_headers();
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
            header('X-Robots-Tag: noindex, nofollow', true);
        }, 1);

        add_filter('show_admin_bar', static function (bool $show): bool {
            $user = wp_get_current_user();
            return in_array(OrganizerAccess::ROLE, (array) $user->roles, true)
                && !user_can($user, 'manage_options') ? false : $show;
        });
        add_action('admin_init', function (): void {
            $user = wp_get_current_user();
            if (!in_array(OrganizerAccess::ROLE, (array) $user->roles, true)
                || user_can($user, 'manage_options')
                || wp_doing_ajax()) {
                return;
            }
            $screen = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
            // admin-post.php is the authenticated action boundary for every
            // portal form; profile.php is the organizer's account settings.
            if (in_array($screen, ['admin-post.php', 'profile.php'], true)) return;
            wp_safe_redirect($this->portalUrl());
            exit;
        }, 1);
        add_filter('login_redirect', function (string $redirect, string $requested, $user): string {
            if ($user instanceof \WP_User
                && in_array(OrganizerAccess::ROLE, (array) $user->roles, true)
                && !user_can($user, 'manage_options')) {
                return $this->portalUrl();
            }
            return $redirect;
        }, 10, 3);

        add_action('admin_menu', function () use ($container): void {
            add_submenu_page(
                'bosquegrande',
                'Event Organizers',
                'Event Organizers',
                'manage_options',
                self::ADMIN_PAGE,
                fn() => $this->renderAdmin($container)
            );
        }, 35);

        $actions = [
            'bg_org_assign' => fn() => $this->handleAssign($container),
            'bg_org_revoke' => fn() => $this->handleRevoke($container),
            'bg_org_review' => fn() => $this->handleReview($container),
            'bg_org_attendance' => fn() => $this->handleAttendance($container),
            'bg_org_message' => fn() => $this->handleMessage($container),
            'bg_org_export' => fn() => $this->handleExport($container),
            'bg_org_propose' => fn() => $this->handleProposal($container),
            'bg_org_create_event' => fn() => $this->handleCreateEvent($container),
        ];
        foreach ($actions as $action => $handler) add_action('admin_post_' . $action, $handler);
    }

    private function renderAdmin(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        /** @var OrganizerRepository $repo */
        $repo = $container->get('organizers.repository');
        echo '<div class="wrap"><h1>Event Organizers</h1>';
        echo '<div class="notice notice-info inline"><p><strong>Organizer app:</strong> <a href="'
            . esc_url(apply_filters('bg_organizer_home_url', home_url('/'))) . '" target="_blank" rel="noopener">Open the Event Organizer App</a></p>'
            . '<p><strong>Fallback portal shortcode:</strong> <code>[' . esc_html(self::SHORTCODE) . ']</code>. The original portal remains available if the app cannot be used.</p></div>';
        $notice = sanitize_key((string) ($_GET['bg_org_admin_notice'] ?? ''));
        $messages = [
            'assigned' => 'Organizer account saved and assigned.',
            'revoked' => 'Organizer assignment revoked.',
            'approved' => 'The organizer request was approved and applied.',
            'rejected' => 'The organizer request was rejected.',
            'failed' => 'The requested organizer action could not be completed.',
        ];
        if (isset($messages[$notice])) {
            echo '<div class="notice ' . ($notice === 'failed' ? 'notice-error' : 'notice-success') . ' is-dismissible"><p>'
                . esc_html($messages[$notice]) . '</p></div>';
        }
        if (!$repo->available()) {
            echo '<div class="notice notice-error"><p>The organizer tables are unavailable. Check BosqueGrande System Status.</p></div></div>';
            return;
        }
        $this->renderPendingRequests($repo);
        $this->renderAssignmentForm();
        $this->renderAssignments($repo);
        $this->renderAudit($repo);
        echo '</div>';
    }

    private function renderPendingRequests(OrganizerRepository $repo): void
    {
        $requests = $repo->pendingRequests();
        echo '<h2>Pending event requests</h2>';
        if (!$requests) {
            echo '<p>No organizer event requests are awaiting review.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Organizer</th><th>Type</th><th>Submitted</th><th>Review</th></tr></thead><tbody>';
        foreach ($requests as $request) {
            $payload = $repo->changePayload((int) $request->id);
            $summary = $payload ? $this->payloadSummary($payload) : 'Encrypted payload could not be opened.';
            echo '<tr><td><strong>' . esc_html((string) $request->post_title) . '</strong><br><small>'
                . esc_html($summary) . '</small></td><td>' . esc_html((string) $request->display_name)
                . '<br><small>' . esc_html((string) $request->user_email) . '</small></td><td>'
                . esc_html(ucfirst((string) $request->request_type)) . '</td><td>'
                . esc_html((string) $request->created_at) . ' UTC</td><td><div style="display:flex;gap:6px">';
            $decisions = ['approved' => 'Approve', 'rejected' => 'Reject'];
            if ((string) $request->status === 'processing') {
                $persisted = sanitize_key((string) get_post_meta(
                    (int) $request->event_post_id,
                    '_bg_organizer_request_' . (int) $request->id . '_decision',
                    true
                ));
                if (!isset($decisions[$persisted])
                    && (string) get_post_meta(
                        (int) $request->event_post_id,
                        '_bg_organizer_request_' . (int) $request->id . '_applied',
                        true
                    ) === '1'
                ) {
                    $persisted = 'approved';
                }
                $decisions = isset($decisions[$persisted])
                    ? [$persisted => ($persisted === 'approved' ? 'Resume approval' : 'Resume rejection')]
                    : [];
            }
            foreach ($decisions as $decision => $label) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bg_org_review">'
                    . '<input type="hidden" name="request_id" value="' . (int) $request->id . '"><input type="hidden" name="decision" value="' . esc_attr($decision) . '">'
                    . wp_nonce_field('bg_org_review_' . (int) $request->id, '_wpnonce', true, false)
                    . '<button class="button ' . ($decision === 'approved' ? 'button-primary' : '') . '" type="submit">' . esc_html($label) . '</button></form>';
            }
            if (!$decisions) {
                echo '<span class="description">This interrupted review has no recoverable decision. Inspect the event and activity log before resetting it.</span>';
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderAssignmentForm(): void
    {
        $events = get_posts([
            'post_type' => 'bg_event',
            'post_status' => 'publish',
            'posts_per_page' => 500,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        echo '<h2>Assign an organizer</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:900px;background:#fff;border:1px solid #ccd0d4;padding:18px">'
            . '<input type="hidden" name="action" value="bg_org_assign">'
            . wp_nonce_field('bg_org_assign', '_wpnonce', true, false)
            . '<table class="form-table"><tr><th><label for="bg-org-email">Organizer email</label></th><td><input class="regular-text" type="email" id="bg-org-email" name="email" required><p class="description">An existing WordPress account is reused. Otherwise BosqueGrande creates the organizer account and sends the standard password-setup email.</p></td></tr>'
            . '<tr><th><label for="bg-org-name">Display name</label></th><td><input class="regular-text" type="text" id="bg-org-name" name="display_name" placeholder="Optional for existing accounts"></td></tr>'
            . '<tr><th><label for="bg-org-event">Event</label></th><td><select id="bg-org-event" name="event_id" required><option value="">Choose an event</option>';
        foreach ($events as $event) {
            echo '<option value="' . (int) $event->ID . '">' . esc_html(get_the_title($event) . ' · ' . (string) get_post_meta($event->ID, '_bg_start_date', true)) . '</option>';
        }
        echo '</select></td></tr>'
            . '<tr><th><label for="bg-org-role">Recorded role</label></th><td><select id="bg-org-role" name="role_label"><option value="organizer">Organizer</option><option value="host">Host</option></select><p class="description">The assignment is linked to the person’s BosqueGrande constituent record and retained in organizer/host history.</p></td></tr>'
            . '<tr><th>Event permissions</th><td>';
        $permissionLabels = [
            'check_in' => 'Check in attendees',
            'walk_in' => 'Register walk-ins',
            'tender' => 'Record cash and check payments',
            'private_answers' => 'Open attendee privacy mode',
            'close_out' => 'Submit event closeout',
            'message' => 'Email attendees',
            'export' => 'Export the event roster',
            'propose' => 'Create and propose event changes',
        ];
        foreach ($permissionLabels as $key => $label) {
            echo '<label style="display:block;margin:0 0 7px"><input type="checkbox" name="permissions[]" value="' . esc_attr($key) . '" '
                . checked(in_array($key, ['check_in', 'message', 'propose'], true), true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</td></tr></table><button class="button button-primary" type="submit">Create or update assignment</button></form>';
    }

    private function renderAssignments(OrganizerRepository $repo): void
    {
        $assignments = $repo->assignments();
        echo '<h2>Organizer assignments</h2>';
        if (!$assignments) {
            echo '<p>No organizers have been assigned yet.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Organizer or host</th><th>Event</th><th>Permissions</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($assignments as $row) {
            $permissions = array_filter([
                (int) $row->can_check_in ? 'check-in' : '',
                (int) $row->can_message ? 'message' : '',
                (int) $row->can_export ? 'export' : '',
                (int) $row->can_propose_edits ? 'propose edits' : '',
                (int) ($row->can_register_walkins ?? 0) ? 'walk-ins' : '',
                (int) ($row->can_record_tenders ?? 0) ? 'cash/check' : '',
                (int) ($row->can_collect_private_answers ?? 0) ? 'privacy mode' : '',
                (int) ($row->can_close_out ?? 0) ? 'closeout' : '',
            ]);
            echo '<tr><td><strong>' . esc_html((string) $row->display_name) . '</strong><br><small>'
                . esc_html((string) $row->user_email) . '</small><br><small>Role: ' . esc_html(ucfirst((string) ($row->role_label ?? 'organizer')))
                . ((int) ($row->constituent_id ?? 0) > 0 ? ' · Constituent #' . (int) $row->constituent_id : ' · Constituent link pending')
                . '</small></td><td>' . esc_html((string) $row->post_title)
                . '</td><td>' . esc_html(implode(', ', $permissions) ?: 'view only') . '</td><td>'
                . esc_html(ucfirst((string) $row->status)) . '</td><td>';
            if ((string) $row->status === 'active') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bg_org_revoke">'
                    . '<input type="hidden" name="event_id" value="' . (int) $row->event_post_id . '"><input type="hidden" name="user_id" value="' . (int) $row->user_id . '">'
                    . wp_nonce_field('bg_org_revoke_' . (int) $row->event_post_id . '_' . (int) $row->user_id, '_wpnonce', true, false)
                    . '<button class="button" type="submit">Revoke</button></form>';
            } else {
                echo '<span class="description">Reassign above to restore.</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderAudit(OrganizerRepository $repo): void
    {
        $rows = $repo->audits(100);
        echo '<h2>Organizer activity</h2>';
        if (!$rows) {
            echo '<p>No organizer actions have been recorded yet.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Account</th><th>Event</th><th>Action</th><th>Detail</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html((string) $row->created_at) . ' UTC</td><td>'
                . esc_html((string) ($row->display_name ?: ('User #' . (int) $row->user_id))) . '</td><td>'
                . esc_html((string) ($row->post_title ?: ('Event #' . (int) $row->event_post_id))) . '</td><td><code>'
                . esc_html((string) $row->action) . '</code></td><td>' . esc_html((string) $row->detail) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function handleAssign(Container $container): void
    {
        $this->requireAdmin();
        check_admin_referer('bg_org_assign');
        $email = strtolower(sanitize_email(wp_unslash((string) ($_POST['email'] ?? ''))));
        $eventId = absint($_POST['event_id'] ?? 0);
        if (!is_email($email) || get_post_type($eventId) !== 'bg_event') $this->adminRedirect('failed');
        $user = get_user_by('email', $email);
        $created = false;
        if (!$user) {
            $display = sanitize_text_field(wp_unslash((string) ($_POST['display_name'] ?? '')));
            $login = $this->uniqueLogin($email);
            $userId = wp_insert_user([
                'user_login' => $login,
                'user_email' => $email,
                'display_name' => $display !== '' ? $display : $login,
                'user_pass' => wp_generate_password(32, true, true),
                'role' => OrganizerAccess::ROLE,
            ]);
            if (is_wp_error($userId)) $this->adminRedirect('failed');
            $user = get_user_by('id', (int) $userId);
            if ($user) {
                $created = true;
                wp_new_user_notification((int) $userId, null, 'user');
            }
        }
        if (!$user instanceof \WP_User) $this->adminRedirect('failed');
        $user->add_role(OrganizerAccess::ROLE);

        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        $localIds = $store->idsByEmail($email);
        if (count($localIds) === 1) \BosqueGrande\Core\UserLink::set((int) $user->ID, (int) $localIds[0]);

        $selected = array_map('sanitize_key', (array) ($_POST['permissions'] ?? []));
        $permissions = array_fill_keys(array_intersect($selected, [
            'check_in', 'message', 'export', 'propose',
            'walk_in', 'tender', 'private_answers', 'close_out',
        ]), true);
        $roleLabel = sanitize_key((string) ($_POST['role_label'] ?? 'organizer'));
        $permissions['role_label'] = in_array($roleLabel, ['organizer', 'host'], true) ? $roleLabel : 'organizer';
        /** @var OrganizerRepository $repo */
        $repo = $container->get('organizers.repository');
        $ok = $repo->assign($eventId, (int) $user->ID, $permissions, get_current_user_id());
        if ($ok && is_email($user->user_email)) {
            $eventTitle = get_the_title($eventId);
            $role = (string) $permissions['role_label'];
            $subject = PortalWording::format(
                'organizer_assignment_subject',
                'Event {role} access: {event}',
                ['role' => $role, 'event' => $eventTitle],
                ['role', 'event']
            );
            $body = PortalWording::format(
                'organizer_assignment_body',
                'You have been assigned {role} access for {event}.',
                ['role' => $role, 'event' => $eventTitle],
                ['role', 'event']
            );
            $body .= "\n\n" . PortalWording::text('organizer_assignment_link_label', 'Organizer app:')
                . ' ' . $this->portalUrl();
            if ($created) {
                $body .= "\n\n" . PortalWording::text(
                    'organizer_assignment_password_note',
                    'WordPress sent a separate secure password-setup message for your new account.'
                );
            }
            wp_mail(
                $user->user_email,
                $subject,
                $body
            );
        }
        $this->adminRedirect($ok ? 'assigned' : 'failed');
    }

    private function handleRevoke(Container $container): void
    {
        $this->requireAdmin();
        $eventId = absint($_POST['event_id'] ?? 0);
        $userId = absint($_POST['user_id'] ?? 0);
        check_admin_referer('bg_org_revoke_' . $eventId . '_' . $userId);
        /** @var OrganizerRepository $repo */
        $repo = $container->get('organizers.repository');
        $ok = $repo->revoke($eventId, $userId, get_current_user_id());
        if ($ok && $repo->activeAssignmentCount($userId) === 0) {
            $user = get_user_by('id', $userId);
            if ($user instanceof \WP_User && !user_can($user, 'manage_options')) {
                $user->remove_role(OrganizerAccess::ROLE);
            }
        }
        $this->adminRedirect($ok ? 'revoked' : 'failed');
    }

    private function handleReview(Container $container): void
    {
        $this->requireAdmin();
        $requestId = absint($_POST['request_id'] ?? 0);
        check_admin_referer('bg_org_review_' . $requestId);
        $decision = sanitize_key((string) ($_POST['decision'] ?? ''));
        if (!in_array($decision, ['approved', 'rejected'], true)) $this->adminRedirect('failed');
        /** @var OrganizerRepository $repo */
        $repo = $container->get('organizers.repository');
        $request = $repo->request($requestId);
        if (!$request || !in_array((string) $request->status, ['pending', 'processing'], true)) $this->adminRedirect('failed');
        $reviewLock = 'bg_organizer_review_lock_' . $requestId;
        $reviewOwner = OptionLease::acquire($reviewLock, 15 * MINUTE_IN_SECONDS);
        if ($reviewOwner === '') $this->adminRedirect('failed');
        register_shutdown_function(static function () use ($reviewLock, $reviewOwner): void {
            OptionLease::release($reviewLock, $reviewOwner);
        });
        $eventId = (int) $request->event_post_id;
        $appliedMarker = '_bg_organizer_request_' . $requestId . '_applied';
        $decisionMarker = '_bg_organizer_request_' . $requestId . '_decision';
        $ownedMarker = '_bg_organizer_request_' . $requestId . '_owned_fields';
        if ((string) $request->status === 'pending') {
            update_post_meta($eventId, $decisionMarker, $decision);
            if ((string) get_post_meta($eventId, $decisionMarker, true) !== $decision) {
                $this->adminRedirect('failed');
            }
            if (!$repo->claimRequest($requestId, get_current_user_id())) {
                delete_post_meta($eventId, $decisionMarker);
                $this->adminRedirect('failed');
            }
        } else {
            $persistedDecision = sanitize_key((string) get_post_meta($eventId, $decisionMarker, true));
            if (!in_array($persistedDecision, ['approved', 'rejected'], true)
                && (string) get_post_meta($eventId, $appliedMarker, true) === '1'
            ) {
                $persistedDecision = 'approved';
                update_post_meta($eventId, $decisionMarker, $persistedDecision);
            }
            if ($persistedDecision !== $decision) $this->adminRedirect('failed');
        }
        if ($decision === 'approved') {
            $payload = $repo->changePayload($requestId);
            $publish = (string) $request->request_type === 'create'
                || (string) get_post_meta($eventId, '_bg_organizer_proposed', true) === '1';
            if (!$payload) {
                if ($repo->releaseRequest($requestId)) {
                    delete_post_meta($eventId, $decisionMarker);
                    delete_post_meta($eventId, $ownedMarker);
                }
                $this->adminRedirect('failed');
            }
            if (metadata_exists('post', $eventId, $ownedMarker)) {
                $ownedChanges = get_post_meta($eventId, $ownedMarker, true);
                $ownedChanges = is_array($ownedChanges)
                    ? array_values(array_intersect(Mapper::OWNED_FIELDS, array_map('strval', $ownedChanges)))
                    : [];
            } else {
                // Persist the pre-application comparison. If the request is
                // interrupted after any event write, a retry must not compare
                // against its own partially applied values and lose the field-
                // ownership protections needed for later source syncs.
                $ownedChanges = (string) get_post_meta($eventId, $appliedMarker, true) === '1'
                    ? array_values(array_intersect(Mapper::OWNED_FIELDS, array_keys($payload)))
                    : $this->changedOwnedFields($eventId, $payload);
                update_post_meta($eventId, $ownedMarker, $ownedChanges);
                if (get_post_meta($eventId, $ownedMarker, true) !== $ownedChanges) {
                    $this->adminRedirect('failed');
                }
            }
            if ((string) get_post_meta($eventId, $appliedMarker, true) !== '1') {
                if (!$this->applyEventPayload($eventId, $payload, $publish)) {
                    $this->adminRedirect('failed');
                }
                if (!$this->markOfficeOwned($eventId, $ownedChanges)) $this->adminRedirect('failed');
                if ($publish) {
                    delete_post_meta($eventId, '_bg_organizer_proposed');
                    if ((string) get_post_meta($eventId, '_bg_organizer_proposed', true) !== '') {
                        $this->adminRedirect('failed');
                    }
                }
                if (update_post_meta($eventId, $appliedMarker, '1') === false) {
                    $this->adminRedirect('failed');
                }
            }
        } elseif ((string) $request->request_type === 'create') {
            $updated = wp_update_post(['ID' => $eventId, 'post_status' => 'draft'], true);
            if (is_wp_error($updated) || !$updated) {
                if ($repo->releaseRequest($requestId)) {
                    delete_post_meta($eventId, $decisionMarker);
                    delete_post_meta($eventId, $ownedMarker);
                }
                $this->adminRedirect('failed');
            }
        }
        if (!$repo->finishRequest($requestId, $decision, get_current_user_id())) $this->adminRedirect('failed');
        delete_post_meta($eventId, $decisionMarker);
        delete_post_meta($eventId, $ownedMarker);
        $repo->audit(get_current_user_id(), $eventId, 0, 'change_' . $decision, 'Request #' . $requestId);
        $organizer = get_user_by('id', (int) $request->user_id);
        if ($organizer instanceof \WP_User && is_email($organizer->user_email)) {
            $eventTitle = get_the_title($eventId);
            $subject = PortalWording::format(
                'organizer_review_subject',
                'Event request {decision}: {event}',
                ['decision' => $decision, 'event' => $eventTitle],
                ['decision', 'event']
            );
            $body = PortalWording::format(
                'organizer_review_body',
                'Your event request #{id} was {decision}.',
                ['id' => $requestId, 'decision' => $decision],
                ['id', 'decision']
            ) . "\n\n" . PortalWording::text('organizer_review_link_label', 'Organizer Portal:')
                . ' ' . $this->portalUrl();
            wp_mail(
                $organizer->user_email,
                $subject,
                $body
            );
        }
        $this->adminRedirect($decision);
    }

    private function handleAttendance(Container $container): void
    {
        $eventId = absint($_POST['event_id'] ?? 0);
        $registrationId = absint($_POST['registration_id'] ?? 0);
        check_admin_referer('bg_org_attendance_' . $eventId . '_' . $registrationId);
        /** @var OrganizerAccess $access */
        $access = $container->get('organizers.access');
        if (!$access->canEvent(get_current_user_id(), $eventId, 'check_in')) {
            wp_die(esc_html(PortalWording::text('organizer_permission_denied', 'Not allowed.')), '', ['response' => 403]);
        }
        $status = sanitize_key((string) ($_POST['attendance_status'] ?? ''));
        /** @var OrganizerRepository $repo */
        $repo = $container->get('organizers.repository');
        $this->portalRedirect($eventId, $repo->setAttendance($eventId, $registrationId, $status, get_current_user_id())
            ? 'attendance_saved' : 'action_failed');
    }

    private function handleMessage(Container $container): void
    {
        $eventId = absint($_POST['event_id'] ?? 0);
        check_admin_referer('bg_org_message_' . $eventId);
        /** @var OrganizerAccess $access */
        $access = $container->get('organizers.access');
        $userId = get_current_user_id();
        if (!$access->canEvent($userId, $eventId, 'message')) {
            wp_die(esc_html(PortalWording::text('organizer_permission_denied', 'Not allowed.')), '', ['response' => 403]);
        }
        $subject = sanitize_text_field(wp_unslash((string) ($_POST['subject'] ?? '')));
        $message = sanitize_textarea_field(wp_unslash((string) ($_POST['message'] ?? '')));
        if ($subject === '' || $message === '') $this->portalRedirect($eventId, 'action_failed');
        /** @var OrganizerRepository $repo */
        $repo = $container->get('organizers.repository');
        $roster = $repo->roster($eventId);
        $recipient = sanitize_text_field((string) ($_POST['recipient'] ?? 'all'));
        $emails = [];
        foreach ($roster as $row) {
            if ($recipient !== 'all' && (int) $recipient !== (int) $row['registration_id']) continue;
            if (empty($row['contact_available']) || !empty($row['contact_withheld'])) continue;
            $email = strtolower((string) $row['email']);
            if (is_email($email)) $emails[$email] = true;
        }
        if (!$emails) $this->portalRedirect($eventId, 'action_failed');
        $bucket = 'bg_org_mail_' . $userId . '_' . $eventId;
        $budgetOwner = OptionLease::acquire($bucket . '_lock', 10);
        if ($budgetOwner === '') $this->portalRedirect($eventId, 'rate_limited');
        try {
            $count = (int) get_transient($bucket);
            $allowed = $count < 5
                && set_transient($bucket, $count + 1, 15 * MINUTE_IN_SECONDS);
        } finally {
            OptionLease::release($bucket . '_lock', $budgetOwner);
        }
        if (!$allowed) $this->portalRedirect($eventId, 'rate_limited');
        $eventTitle = get_the_title($eventId);
        $sender = wp_get_current_user()->display_name;
        $sender = $sender ?: PortalWording::text('organizer_sender_fallback', 'Event organizer');
        $signature = PortalWording::format(
            'organizer_mail_signature',
            'Sent by {sender}',
            ['sender' => $sender],
            ['sender']
        );
        $context = PortalWording::format(
            'organizer_mail_context',
            'Regarding: {event}',
            ['event' => $eventTitle],
            ['event']
        );
        $body = $message . "\n\n" . $signature . "\n" . $context;
        $accepted = 0;
        foreach (array_keys($emails) as $email) {
            if (wp_mail($email, '[' . $eventTitle . '] ' . $subject, $body)) $accepted++;
        }
        $repo->audit($userId, $eventId, 0, 'registrants_messaged', $accepted . ' of ' . count($emails) . ' accepted');
        $this->portalRedirect($eventId, $accepted === count($emails) ? 'message_sent' : 'message_partial');
    }

    private function handleExport(Container $container): void
    {
        $eventId = absint($_POST['event_id'] ?? 0);
        check_admin_referer('bg_org_export_' . $eventId);
        /** @var OrganizerAccess $access */
        $access = $container->get('organizers.access');
        if (!$access->canEvent(get_current_user_id(), $eventId, 'export')) {
            wp_die(esc_html(PortalWording::text('organizer_permission_denied', 'Not allowed.')), '', ['response' => 403]);
        }
        /** @var OrganizerRepository $repo */
        $repo = $container->get('organizers.repository');
        $roster = $repo->roster($eventId);
        $slug = sanitize_title(get_the_title($eventId)) ?: 'event';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename=bg-' . $slug . '-roster-' . gmdate('Ymd-His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'Registration ID', 'Name', 'Email', 'Phone', 'Address 1', 'Address 2',
            'City', 'State', 'Postal code', 'Contact sharing', 'Tier',
            'Registration Status', 'Attendance', 'Registered UTC',
        ], ',', '"', '');
        foreach ($roster as $row) {
            fputcsv($out, array_map([$this, 'csvCell'], [
                $row['registration_id'], $row['name'], $row['email'], $row['phone'],
                $row['address1'], $row['address2'], $row['city'], $row['state'], $row['postal'],
                !empty($row['contact_withheld'])
                    ? 'Withheld by registrant'
                    : (!empty($row['contact_available']) ? 'Allowed' : 'Unavailable'),
                $row['tier'], $row['registration_status'], $row['attendance_status'], $row['created_at'],
            ]), ',', '"', '');
        }
        fclose($out);
        $repo->audit(get_current_user_id(), $eventId, 0, 'roster_exported', count($roster) . ' rows');
        exit;
    }

    private function handleProposal(Container $container): void
    {
        $eventId = absint($_POST['event_id'] ?? 0);
        check_admin_referer('bg_org_propose_' . $eventId);
        /** @var OrganizerAccess $access */
        $access = $container->get('organizers.access');
        $userId = get_current_user_id();
        if (!$access->canEvent($userId, $eventId, 'propose')) {
            wp_die(esc_html(PortalWording::text('organizer_permission_denied', 'Not allowed.')), '', ['response' => 403]);
        }
        $payload = $this->eventPayload($_POST);
        if (!$this->validEventPayload($payload)) $this->portalRedirect($eventId, 'action_failed');
        /** @var OrganizerRepository $repo */
        $repo = $container->get('organizers.repository');
        $id = $repo->createChangeRequest($eventId, $userId, 'edit', $payload);
        if ($id) $this->notifyStaffRequest($eventId, $userId, $id, 'edit');
        $this->portalRedirect($eventId, $id ? 'request_saved' : ($repo->hasPendingRequest($eventId, $userId) ? 'request_pending' : 'action_failed'));
    }

    private function handleCreateEvent(Container $container): void
    {
        check_admin_referer('bg_org_create_event');
        $userId = get_current_user_id();
        /** @var OrganizerAccess $access */
        $access = $container->get('organizers.access');
        if (!$access->canCreate($userId)) {
            wp_die(esc_html(PortalWording::text('organizer_permission_denied', 'Not allowed.')), '', ['response' => 403]);
        }
        $payload = $this->eventPayload($_POST);
        if (!$this->validEventPayload($payload)) $this->portalRedirect(0, 'action_failed');
        $eventId = wp_insert_post([
            'post_type' => 'bg_event',
            'post_status' => 'pending',
            'post_author' => $userId,
            'post_title' => $payload['post_title'],
            'post_content' => $payload['post_content'],
        ], true);
        if (is_wp_error($eventId) || (int) $eventId < 1) $this->portalRedirect(0, 'action_failed');
        $eventId = (int) $eventId;
        if (!$this->applyEventPayload($eventId, $payload, false)) {
            wp_trash_post($eventId);
            $this->portalRedirect(0, 'action_failed');
        }
        /** @var OrganizerRepository $repo */
        $repo = $container->get('organizers.repository');
        $permissions = ['check_in' => true, 'message' => true, 'export' => true, 'propose' => true];
        $assigned = $repo->assign($eventId, $userId, $permissions, $userId);
        $requestId = $assigned ? $repo->createChangeRequest($eventId, $userId, 'create', $payload) : 0;
        if (!$assigned || !$requestId) {
            if ($assigned) $repo->revoke($eventId, $userId, $userId);
            wp_trash_post($eventId);
            $this->portalRedirect(0, 'action_failed');
        }
        update_post_meta($eventId, '_bg_organizer_proposed', '1');
        $this->notifyStaffRequest($eventId, $userId, $requestId, 'create');
        // Pending proposals are reviewed from the staff screen and do not
        // appear in the published-only organizer portal until approved.
        $this->portalRedirect(0, 'event_saved');
    }

    /** @return array<string, mixed> */
    private function eventPayload(array $input): array
    {
        $raw = static function (string $key) use ($input): string {
            $value = $input[$key] ?? '';
            return is_scalar($value) ? wp_unslash((string) $value) : '';
        };
        $interestInput = array_filter((array) ($input['interest_slugs'] ?? []), 'is_scalar');
        return [
            'post_title' => sanitize_text_field($raw('event_title')),
            'post_content' => wp_kses_post($raw('description')),
            '_bg_start_date' => $this->date($raw('start_date')),
            '_bg_start_time' => sanitize_text_field($raw('start_time')),
            '_bg_end_date' => $this->date($raw('end_date')),
            '_bg_end_time' => sanitize_text_field($raw('end_time')),
            '_bg_reg_url' => esc_url_raw($raw('registration_url')),
            '_bg_image' => esc_url_raw($raw('image_url')),
            'location_slug' => sanitize_title($raw('location_slug')),
            'interest_slugs' => array_values(array_unique(array_filter(array_map(
                static fn($value): string => sanitize_title(wp_unslash((string) $value)),
                $interestInput
            )))),
        ];
    }

    private function validEventPayload(array $payload): bool
    {
        return trim((string) ($payload['post_title'] ?? '')) !== ''
            && trim(wp_strip_all_tags((string) ($payload['post_content'] ?? ''))) !== ''
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($payload['_bg_start_date'] ?? '')) === 1
            && ((string) ($payload['_bg_end_date'] ?? '') === ''
                || (string) $payload['_bg_end_date'] >= (string) $payload['_bg_start_date']);
    }

    private function applyEventPayload(int $eventId, array $payload, bool $publish): bool
    {
        $post = get_post($eventId);
        if (!$post || $post->post_type !== 'bg_event') return false;
        $updated = wp_update_post([
            'ID' => $eventId,
            'post_title' => (string) $payload['post_title'],
            'post_content' => (string) $payload['post_content'],
            'post_status' => $publish ? 'publish' : $post->post_status,
        ], true);
        if (is_wp_error($updated) || !$updated) return false;
        foreach (['_bg_start_date', '_bg_start_time', '_bg_end_date', '_bg_end_time', '_bg_reg_url', '_bg_image'] as $meta) {
            $value = (string) ($payload[$meta] ?? '');
            update_post_meta($eventId, $meta, $value);
            if ((string) get_post_meta($eventId, $meta, true) !== $value) return false;
        }
        return $this->applyTaxonomies($eventId, $payload);
    }

    private function applyTaxonomies(int $eventId, array $payload): bool
    {
        $locationTax = LocationTaxonomy::taxonomy();
        $location = sanitize_title((string) ($payload['location_slug'] ?? ''));
        $interests = array_values(array_filter(array_map('sanitize_title', (array) ($payload['interest_slugs'] ?? []))));
        if ($locationTax !== 'bg_tag' && taxonomy_exists($locationTax)) {
            $term = $location !== '' ? get_term_by('slug', $location, $locationTax) : null;
            $set = wp_set_object_terms($eventId, $term && !is_wp_error($term) ? [$location] : [], $locationTax, false);
            if (is_wp_error($set)) return false;
        }
        if (!taxonomy_exists('bg_tag')) return true;
        $selected = array_values(array_filter($interests, static function (string $slug): bool {
            $term = get_term_by('slug', $slug, 'bg_tag');
            return (bool) ($term && !is_wp_error($term));
        }));
        if ($locationTax === 'bg_tag' && $location !== '') {
            $term = get_term_by('slug', $location, 'bg_tag');
            if ($term && !is_wp_error($term)) $selected[] = $location;
        }
        $current = wp_get_post_terms($eventId, 'bg_tag', ['fields' => 'slugs']);
        $known = array_keys(\BosqueGrande\Modules\GatheringTree\Vocabulary::all());
        if (is_wp_error($current)) return false;
        $preserve = array_values(array_diff($current, $known));
        $set = wp_set_object_terms($eventId, array_values(array_unique(array_merge($preserve, $selected))), 'bg_tag', false);
        return !is_wp_error($set);
    }

    /** @param list<string> $fields */
    private function markOfficeOwned(int $eventId, array $fields): bool
    {
        if ((string) get_post_meta($eventId, '_bg_src_key', true) === '') return true;
        $own = get_post_meta($eventId, '_gt_ownership', true);
        $own = is_array($own) ? $own : [];
        foreach ($fields as $field) if (in_array($field, Mapper::OWNED_FIELDS, true)) $own[$field] = 'admin';
        update_post_meta($eventId, '_gt_ownership', $own);
        return get_post_meta($eventId, '_gt_ownership', true) === $own;
    }

    /** @return list<string> */
    private function changedOwnedFields(int $eventId, array $payload): array
    {
        $post = get_post($eventId);
        if (!$post instanceof \WP_Post) return [];
        $changed = [];
        foreach (Mapper::OWNED_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) continue;
            if ($field === 'post_title') $current = (string) $post->post_title;
            elseif ($field === 'post_content') $current = (string) $post->post_content;
            elseif ($field === '_bg_reg_url' && function_exists('get_metadata_raw')) {
                $current = (string) get_metadata_raw('post', $eventId, $field, true);
            } else $current = (string) get_post_meta($eventId, $field, true);
            if ($current !== (string) $payload[$field]) $changed[] = $field;
        }
        return $changed;
    }

    private function date(string $value): string
    {
        $value = sanitize_text_field($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))) {
            return '';
        }
        return $date->format('Y-m-d') === $value ? $value : '';
    }

    private function payloadSummary(array $payload): string
    {
        $times = trim((string) ($payload['_bg_start_time'] ?? ''));
        if (trim((string) ($payload['_bg_end_time'] ?? '')) !== '') {
            $times .= ($times !== '' ? ' – ' : '') . trim((string) $payload['_bg_end_time']);
        }
        return trim((string) ($payload['_bg_start_date'] ?? '') . ' · ' . $times
            . ' · ' . wp_trim_words(wp_strip_all_tags((string) ($payload['post_content'] ?? '')), 18, '…'));
    }

    private function uniqueLogin(string $email): string
    {
        $base = sanitize_user((string) strstr($email, '@', true), true) ?: 'organizer';
        $candidate = $base;
        $i = 2;
        while (username_exists($candidate)) $candidate = $base . $i++;
        return $candidate;
    }

    private function portalUrl(): string
    {
        $pageId = (int) get_option('bg_organizer_portal_page_id', 0);
        if ($pageId < 1) {
            global $wpdb;
            $pageId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s
                 ORDER BY ID ASC LIMIT 1",
                '%[' . $wpdb->esc_like(self::SHORTCODE) . '%'
            ));
            if ($pageId > 0) update_option('bg_organizer_portal_page_id', $pageId, false);
        }
        $url = $pageId > 0 ? get_permalink($pageId) : '';
        $fallback = $url ? (string) $url : home_url('/');
        return (string) apply_filters('bg_organizer_home_url', $fallback);
    }

    private function csvCell(mixed $value): string
    {
        $value = (string) $value;
        // Spreadsheet applications can ignore leading control/space bytes
        // before a formula marker, so test the first non-whitespace byte but
        // preserve the original cell after neutralizing it with an apostrophe.
        return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'" . $value : $value;
    }

    private function notifyStaffRequest(int $eventId, int $userId, int $requestId, string $type): void
    {
        $adminEmail = (string) get_option('admin_email', '');
        if (!is_email($adminEmail)) return;
        $user = get_user_by('id', $userId);
        $organizer = $user instanceof \WP_User ? $user->display_name : 'An organizer';
        wp_mail(
            $adminEmail,
            'Organizer ' . $type . ' request: ' . get_the_title($eventId),
            $organizer . ' submitted request #' . $requestId . " for staff review.\n\nReview: "
                . add_query_arg('page', self::ADMIN_PAGE, admin_url('admin.php'))
        );
    }

    private function portalRedirect(int $eventId, string $notice): void
    {
        $fallback = $this->portalUrl();
        $referer = wp_get_referer();
        $target = $referer ? wp_validate_redirect($referer, $fallback) : $fallback;
        $target = remove_query_arg('bg_org_notice', $target);
        $args = ['bg_org_notice' => $notice];
        if ($eventId > 0) $args['bg_organizer_event'] = $eventId;
        wp_safe_redirect(add_query_arg($args, $target));
        exit;
    }

    private function adminRedirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => self::ADMIN_PAGE,
            'bg_org_admin_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    private function requireAdmin(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.', '', ['response' => 403]);
    }
}
