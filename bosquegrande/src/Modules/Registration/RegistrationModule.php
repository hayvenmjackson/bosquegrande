<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Registration;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\Logger;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Correspondence\Groups;
use BosqueGrande\Modules\Lgl\LglClient;
use BosqueGrande\Modules\Payments\StripeClient;
use BosqueGrande\Modules\Payments\PaymentTrust;

if (!defined('ABSPATH')) exit;

/**
 * Registration Engine (ADR-1, ADR-2). One universal form at
 * /bg-register/{event_post_id}/, wired to every calendar REGISTER
 * button via a get_post_metadata filter on _bg_reg_url (no legacy
 * edits). Constituent and registration writes commit locally. LGL is
 * touched only by deliberate Data Transfer/LGL Sync actions, never by
 * a visitor-facing registration request.
 */
final class RegistrationModule implements ModuleInterface
{
    /**
     * Honeypot field name. Deliberately meaningless: any name a browser
     * or password manager recognises (website, url, company, address)
     * gets autofilled and turns the trap on real people.
     */
    public const HONEYPOT = 'bg_confirm_9f2c';

    public function name(): string
    {
        return 'registration';
    }

    public function label(): string
    {
        return 'Registration Engine';
    }

    public function activate(Container $container): void
    {
        $this->addRewrite();
        if (isset($GLOBALS['wp_rewrite']) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite) {
            flush_rewrite_rules();
        }
        delete_option('bg_rewrites_flushed');
    }

    public function boot(Container $container): void
    {
        add_filter('bg_wording_defaults', [RegistrationWording::class, 'defaults']);

        $container->set('registration.repo', fn() => new RegistrationRepository());
        $container->set('registration.prefill', fn(Container $c) => new PrefillResolver(
            $c->get('constituents.store'), $c->get('logger')
        ));
        $container->set('registration.form', fn(Container $c) => new RegistrationForm(
            $c->get('registration.prefill'),
            $c->get('settings'),
            MonthlyDonor::fromContainer($c),
            $c->has('correspondence.groups') ? $c->get('correspondence.groups') : null
        ));
        $container->set('registration.monthly_donor', fn(Container $c) => MonthlyDonor::fromContainer($c));
        // A deliberate LGL pull may change the Monthly Donor Yes/No
        // field. Drop the short verdict cache immediately so the profile
        // and registration gate see that reviewed value on the next read.
        add_action('bg_lgl_pulled_constituent', function (int $localId) use ($container): void {
            if (!$container->has('constituents.store')) return;
            /** @var ConstituentStore $store */
            $store = $container->get('constituents.store');
            $record = $store->get($localId);
            if (!$record) return;
            /** @var MonthlyDonor $monthly */
            $monthly = $container->get('registration.monthly_donor');
            $monthly->clearCache(
                (string) ($record['email'] ?? ''),
                (int) ($record['lgl_constituent_id'] ?? 0)
            );
        }, 20, 1);
        $container->set('registration.answer_retry', fn(Container $c) => new AnswerRetry(
            $c->get('constituents.store'),
            $c->get('schema')
        ));

        // WordPress creates its rewrite engine after plugins load, so the
        // rule is added on init. Rules are flushed once per plugin version.
        add_action('init', function (): void {
            $this->addRewrite();
        });
        add_action('init', function (): void {
            if (get_option('bg_rewrites_flushed') === BG_VERSION) return;
            flush_rewrite_rules(false);
            update_option('bg_rewrites_flushed', BG_VERSION, false);
        }, 99);

        add_filter('query_vars', function (array $vars): array {
            $vars[] = 'bg_register';
            return $vars;
        });

        add_filter('bg_apply_writeback', function ($applied, int $constituentId, array $d) use ($container) {
            if ($applied || !$constituentId || !$d) return $applied;
            /** @var LglClient $lgl */
            $lgl = $container->get('lgl');
            if (!$lgl->hasKey()) return false;
            $result = $this->upsertConstituent($lgl, $constituentId, $d);
            return $result > 0;
        }, 10, 3);

        add_filter('bg_native_registration', function ($v) use ($container) {
            return (int) $container->get('settings')->get('native_registration_enabled', 1) === 1 ? $v : false;
        }, 5);

        // Route every calendar REGISTER button to the native form.
        add_filter('get_post_metadata', function ($value, $postId, $key) {
            if ($key !== '_bg_reg_url') return $value;
            if (get_post_type($postId) !== 'bg_event') return $value;
            if (!apply_filters('bg_native_registration', true)) return $value;
            return home_url('/bg-register/' . (int) $postId . '/');
        }, 10, 3);

        add_action('template_redirect', function () use ($container) {
            $eventId = (int) get_query_var('bg_register');
            if (!$eventId) return;
            $this->renderPage($container, $eventId);
            exit;
        });

        add_action('rest_api_init', function () use ($container) {
            register_rest_route('bosquegrande/v1', '/register', [
                'methods' => 'POST',
                'permission_callback' => '__return_true', // Public form; validated inside.
                'callback' => fn(\WP_REST_Request $req) => $this->handleSubmit($container, $req),
            ]);
            register_rest_route('bosquegrande/v1', '/reg-nonce', [
                'methods' => 'GET',
                'permission_callback' => '__return_true',
                'callback' => function (\WP_REST_Request $req) {
                    $eventId = (int) $req->get_param('event');
                    if ($eventId < 1) return new \WP_REST_Response(['ok' => false], 400);
                    $event = get_post($eventId);
                    if (!$event instanceof \WP_Post || $event->post_type !== 'bg_event' || $event->post_status !== 'publish') {
                        return new \WP_REST_Response(['ok' => false, 'message' => RegistrationWording::text(
                            'registration_event_not_found',
                            'This event could not be found.'
                        )], 404);
                    }
                    if (self::eventHasEnded($eventId)) {
                        return self::endedRegistrationResponse();
                    }
                    nocache_headers();
                    return new \WP_REST_Response([
                        'ok' => true,
                        'nonce' => wp_create_nonce('bg_register_' . $eventId),
                    ], 200);
                },
            ]);
        });
    }

    public function deactivate(Container $container): void
    {
    }

    private function addRewrite(): void
    {
        if (!isset($GLOBALS['wp_rewrite']) || !($GLOBALS['wp_rewrite'] instanceof \WP_Rewrite)) return;
        add_rewrite_rule('^bg-register/([0-9]+)/?$', 'index.php?bg_register=$matches[1]', 'top');
    }

    private function renderPage(Container $container, int $eventId): void
    {
        $event = get_post($eventId);
        if (!$event instanceof \WP_Post
            || $event->post_type !== 'bg_event'
            || $event->post_status !== 'publish') {
            $this->renderUnavailablePage();
            return;
        }
        if ($event instanceof \WP_Post
            && $event->post_type === 'bg_event'
            && $event->post_status === 'publish'
            && self::eventHasEnded($eventId)) {
            $this->renderEndedPage($event);
            return;
        }

        $identity = $this->currentIdentity($container);

        /** @var RegistrationForm $form */
        $form = $container->get('registration.form');
        // Resolve the live Subscriber Group catalog at the point of render.
        // This prevents an early-resolved RegistrationForm from retaining a
        // null optional dependency and silently omitting the whole section.
        $groups = null;
        if ($container->has('correspondence.groups')) {
            try {
                $candidate = $container->get('correspondence.groups');
                if ($candidate instanceof Groups) $groups = $candidate;
            } catch (\Throwable $e) {
                $container->get('logger')->error(
                    'registration',
                    'Newsletter choices could not be loaded for the registration form; registration remains available.',
                    ['event' => $eventId, 'error' => $e->getMessage()]
                );
            }
        }
        $html = $form->render($eventId, [
            'source' => $identity['source'],
            'constituent_id' => $identity['constituent_id'] ?: null,
            'via_session' => $identity['via_session'],
        ], $groups);

        /** @var StripeClient $stripe */
        $stripe = $container->get('stripe');
        $stripeReady = $stripe->isConfigured();

        // The rewrite endpoint is not a WordPress post and some installations
        // leave the main query's provisional 404 flag set. Clear it only after
        // the event has passed every public/ended check so SEO integrations
        // and body_class() see this valid OneForm as HTTP 200, not noindex.
        global $wp_query;
        if ($wp_query instanceof \WP_Query) $wp_query->is_404 = false;
        status_header(200);
        nocache_headers();
        $eventTitle = (string) get_the_title($event);
        add_filter('pre_get_document_title', static function () use ($eventTitle): string {
            return RegistrationWording::format(
                'registration_document_title',
                'Register: {event}',
                ['event' => $eventTitle]
            );
        }, PHP_INT_MAX);
        $scripts = '';
        if ($stripeReady) {
            $scripts .= '<script src="https://js.stripe.com/v3/"></script>';
            $scripts .= '<script>window.BG_STRIPE_PK=' . wp_json_encode($stripe->publishableKey()) . ';</script>';
        }
        $scripts .= '<script>' . $this->js() . '</script>';
        $this->renderOwnedDocument(
            '<style>' . $this->css() . '</style><div class="bg-reg-wrap">' . $html . '</div>',
            'bg-registration-app-page',
            $scripts
        );
    }

    private static function eventHasEnded(int $eventId): bool
    {
        return function_exists('bgev_event_is_past') && bgev_event_is_past($eventId);
    }

    private static function endedRegistrationResponse(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'ok' => false,
            'code' => 'event_ended',
            'message' => RegistrationWording::text(
                'registration_ended_message',
                'Registration is closed because this event has ended.'
            ),
        ], 410, ['Cache-Control' => 'no-store']);
    }

    private function renderUnavailablePage(): void
    {
        status_header(404);
        nocache_headers();
        if (!headers_sent()) header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        add_filter('wp_robots', static function (array $robots): array {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
            $robots['noarchive'] = true;
            return $robots;
        }, PHP_INT_MAX);
        add_filter('pre_get_document_title', static fn(): string => RegistrationWording::text(
            'registration_unavailable_document_title',
            'Registration unavailable'
        ), PHP_INT_MAX);
        $returnUrl = function_exists('bgev_calendar_return_url')
            ? (string) bgev_calendar_return_url()
            : home_url('/calendar/');

        $content = '<style>' . $this->css() . '</style>';
        $content .= '<div class="bg-reg-wrap"><section class="bg-reg bg-reg-ended" aria-labelledby="bg-registration-unavailable-title">';
        $content .= '<p class="bg-reg-status">' . esc_html(RegistrationWording::text('registration_unavailable_status', 'Not available')) . '</p>';
        $content .= '<h1 class="bg-reg-title" id="bg-registration-unavailable-title">' . esc_html(RegistrationWording::text('registration_unavailable_heading', 'Registration is unavailable')) . '</h1>';
        $content .= '<p>' . esc_html(RegistrationWording::text('registration_unavailable_body', 'This registration page could not be found or is no longer public.')) . '</p>';
        $content .= '<p><a class="bg-btn bg-ended-return" href="' . esc_url($returnUrl) . '">&larr; ' . esc_html(RegistrationWording::text('registration_view_other_events', 'View other events')) . '</a></p>';
        $content .= '</section></div>';
        $this->renderOwnedDocument($content, 'bg-registration-app-page bg-registration-unavailable-page');
    }

    private function renderEndedPage(\WP_Post $event): void
    {
        $eventId = (int) $event->ID;
        $title = (string) get_the_title($event);
        $date = function_exists('bgev_date')
            ? (string) bgev_date($eventId)
            : (string) get_post_meta($eventId, '_bg_start_date', true);
        $time = function_exists('bgev_time_range')
            ? (string) bgev_time_range($eventId)
            : (string) get_post_meta($eventId, '_bg_start_time', true);
        $when = implode(' · ', array_values(array_filter([$date, $time], static fn(string $part): bool => $part !== '')));
        $returnUrl = function_exists('bgev_calendar_return_url')
            ? (string) bgev_calendar_return_url()
            : home_url('/calendar/');

        status_header(410);
        nocache_headers();
        if (!headers_sent()) header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        add_filter('wp_robots', static function (array $robots): array {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
            $robots['noarchive'] = true;
            return $robots;
        }, PHP_INT_MAX);
        add_filter('pre_get_document_title', static function () use ($title): string {
            return RegistrationWording::format(
                'registration_ended_document_title',
                'Registration ended: {event}',
                ['event' => $title]
            );
        }, PHP_INT_MAX);

        $content = '<style>' . $this->css() . '</style>';
        $content .= '<div class="bg-reg-wrap"><section class="bg-reg bg-reg-ended" aria-labelledby="bg-registration-ended-title">';
        $content .= '<p class="bg-reg-status">' . esc_html(RegistrationWording::text('registration_ended_status', 'Event ended')) . '</p>';
        $content .= '<h1 class="bg-reg-title" id="bg-registration-ended-title">' . esc_html(RegistrationWording::text('registration_ended_heading', 'Registration has ended')) . '</h1>';
        $endedBody = RegistrationWording::format(
            'registration_ended_body',
            'Registration for {event} is closed because this event has ended.',
            ['event' => '<strong>' . esc_html($title) . '</strong>']
        );
        $content .= '<p>' . wp_kses_post($endedBody) . '</p>';
        if ($when !== '') $content .= '<p class="bg-reg-when">' . esc_html($when) . '</p>';
        $content .= '<p><a class="bg-btn bg-ended-return" href="' . esc_url($returnUrl) . '">&larr; ' . esc_html(RegistrationWording::text('registration_view_other_events', 'View other events')) . '</a></p>';
        $content .= '</section></div>';
        $this->renderOwnedDocument($content, 'bg-registration-app-page bg-registration-ended-page');
    }

    /** Emit the OneForm inside the same theme-independent document as Events. */
    private function renderOwnedDocument(string $content, string $bodyClass, string $scripts = ''): void
    {
        $calendarUrl = function_exists('bgev_calendar_return_url')
            ? (string) bgev_calendar_return_url()
            : home_url('/calendar/');
        if (function_exists('bgev_public_app_shell')) {
            $content = bgev_public_app_shell($content, [
                'context' => 'registration',
                'main_id' => 'bg-registration-app-main',
                'skip_label' => RegistrationWording::text('registration_skip_label', 'Skip to event registration'),
                'right_url' => $calendarUrl,
            ]);
        }
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <?php if (!current_theme_supports('title-tag')) : ?>
                <title><?php echo esc_html(wp_get_document_title()); ?></title>
            <?php endif; ?>
            <meta name="bosquegrande-shell-template" content="route-registration">
            <?php wp_head(); ?>
        </head>
        <body <?php body_class('bg-event-app-page bg-public-app-page ' . $bodyClass); ?>>
        <?php wp_body_open(); ?>
        <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by RegistrationForm/shared shell. ?>
        <?php echo $scripts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed scripts and JSON-encoded public key. ?>
        <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }

    private function handleSubmit(Container $container, \WP_REST_Request $req): \WP_REST_Response
    {
        /** @var Logger $logger */
        $logger = $container->get('logger');

        // Validate the event before any bot shortcut, rate limiter, identity
        // resolution, payment work, or local write. A cached form for an event
        // that has since ended must never receive a success-shaped response.
        $eventId = (int) $req->get_param('event');
        $event = get_post($eventId);
        if (!$event instanceof \WP_Post || $event->post_type !== 'bg_event' || $event->post_status !== 'publish') {
            return new \WP_REST_Response([
                'ok' => false,
                'message' => RegistrationWording::text(
                    'registration_event_not_found',
                    'This event could not be found.'
                ),
            ], 404, ['Cache-Control' => 'no-store']);
        }
        if (self::eventHasEnded($eventId)) {
            return self::endedRegistrationResponse();
        }

        // Bot traps.
        //
        // The honeypot used to be a field named "website",
        // hidden by moving it off-screen, and filling it discarded the
        // entire submission while replying "your registration is
        // confirmed". Both halves of that were wrong. Browsers and
        // password managers fill a field called "website" from the
        // user's saved identity, ignore autocomplete="off" while doing
        // it, and treat an off-screen field as perfectly fillable, so
        // real people were being silently deleted and told they had
        // succeeded. Nothing was written and nothing was logged.
        //
        // The field is now named unrecognisably and hidden with
        // display:none, which autofill skips. More importantly the trap
        // no longer discards on its own evidence: a tripped honeypot
        // only stands when the timing is also inhuman. Anything else is
        // recorded and then processed as the real registration it
        // almost certainly is. Losing one real member costs far more
        // than accepting one spam row that rate limiting already caps.
        $trapped = (string) $req->get_param(self::HONEYPOT) !== ''
            || (string) $req->get_param('website') !== '';
        $tooFast = time() - (int) $req->get_param('ts') < 3;

        if ($trapped) {
            $logger->warn('registration', 'Honeypot field arrived filled.', [
                'discarded' => $tooFast,
                'note' => $tooFast
                    ? 'Timing also implausible; treated as automated.'
                    : 'Timing looks human, so the registration was processed normally. Most likely browser autofill.',
            ]);
        }
        if ($trapped && $tooFast) {
            return $this->ok(RegistrationWording::text(
                'registration_bot_success',
                'Thanks! Your registration is confirmed.'
            ));
        }
        if ($tooFast) {
            return $this->fail(RegistrationWording::text(
                'registration_too_fast',
                'That was quick. Please review the form and try again.'
            ));
        }
        if (!wp_verify_nonce((string) $req->get_param('nonce'), 'bg_register_' . $eventId)) {
            return new \WP_REST_Response([
                'ok' => false,
                'code' => 'stale_form',
                'message' => RegistrationWording::text(
                    'registration_stale_form',
                    'This form has been open a while & must be refreshed. Refreshing & resubmitting now.'
                ),
            ], 400);
        }
        if ($this->rateLimited()) {
            return $this->fail(RegistrationWording::text(
                'registration_rate_limit',
                'Too many attempts. Please wait a few minutes and try again.'
            ));
        }
        $tier = sanitize_key((string) $req->get_param('tier'));
        if (!Tiers::offeredFor($tier, $eventId)) {
            return $this->fail(RegistrationWording::text(
                'registration_choose_type_error',
                'Please choose a registration type.'
            ));
        }

        $first = sanitize_text_field((string) $req->get_param('first_name'));
        $last = sanitize_text_field((string) $req->get_param('last_name'));
        $email = strtolower(sanitize_email((string) $req->get_param('email')));
        if ($first === '' || $last === '' || !is_email($email)) {
            return $this->fail(RegistrationWording::text(
                'registration_contact_error',
                'Please fill in your first name, last name, and a valid email.'
            ));
        }

        // Resolve source-aware identity. A local identity may carry a
        // linked LGL id; an old LGL identity remains compatible without
        // making a remote request.
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        $identity = $this->currentIdentity($container);
        $localConstituentId = 0;
        $lglConstituentId = 0;
        $confirmedSubscriptionProfileId = 0;

        if ($identity['source'] === 'bg' && $identity['constituent_id'] && $store->available()) {
            $local = $store->get($identity['constituent_id']);
            if ($local && ($local['status'] ?? '') === 'active' && ConstituentStore::normEmail((string) ($local['email'] ?? '')) === $email) {
                $localConstituentId = (int) $identity['constituent_id'];
                $lglConstituentId = (int) ($local['lgl_constituent_id'] ?? 0);
            }
        } elseif ($identity['source'] === 'lgl') {
            $lglConstituentId = (int) $identity['constituent_id'];
        }

        // Only a verified device session may reveal and clear existing
        // subscriptions. Resolve the stable local record again on submit
        // and require the signed render-time management context to name the
        // same event and local record. This prevents a session created or
        // changed in another tab from turning visually blank public/Tier 1
        // boxes into opt-outs. Never trust a hidden boolean or forwarded
        // Tier 1 link for unsubscribe access.
        if (!empty($identity['via_session']) && !empty($identity['constituent_id'])) {
            $managementToken = sanitize_text_field((string) $req->get_param('subscription_context'));
            $managementContext = $managementToken !== ''
                ? \BosqueGrande\Modules\Correspondence\Composer::verify($managementToken)
                : null;
            /** @var PrefillResolver $subscriptionResolver */
            $subscriptionResolver = $container->get('registration.prefill');
            $subscriptionIdentity = $subscriptionResolver->resolve([
                'source' => (string) $identity['source'],
                'constituent_id' => (int) $identity['constituent_id'],
                'via_session' => true,
            ]);
            $candidateId = (int) ($subscriptionIdentity['local_id'] ?? 0);
            $candidate = $candidateId > 0 ? $store->get($candidateId) : null;
            if ($candidate
                && ($candidate['status'] ?? '') === 'active'
                && ConstituentStore::normEmail((string) ($candidate['email'] ?? '')) === $email
                && is_array($managementContext)
                && (string) ($managementContext['a'] ?? '') === 'registration-subscriptions'
                && (int) ($managementContext['e'] ?? 0) === $eventId
                && (int) ($managementContext['c'] ?? 0) === $candidateId
            ) {
                $confirmedSubscriptionProfileId = $candidateId;
            }
        }

        $wasExistingLgl = $lglConstituentId > 0;

        // Monthly-donor option: server-side gate, never trust the client.
        // Passage: the local register, a live Stripe subscription, or the
        // Complimentary Admission Register.
        if (Tiers::isGated($tier)) {
            $donor = MonthlyDonor::fromContainer($container);
            $isComplimentary = $this->onComplimentaryRegister($container, $email);
            if ($donor->enforced()) {
                // Only a signed-in session for this same email can claim the
                // option; a typed email proves nothing and must not reveal
                // who is a donor.
                $sessionMatches = $localConstituentId > 0 && !empty($identity['via_session']);
                $verdict = $sessionMatches
                    ? $donor->status($email, 0)
                    : ['yes' => false, 'reason' => MonthlyDonor::REASON_NONE];
                $isComplimentary = $sessionMatches && $isComplimentary;
                if (!$verdict['yes'] && !$isComplimentary) {
                    return $this->fail(RegistrationWording::text(
                        'registration_donor_gate_error',
                        'The free option is reserved for Monthly Donors. Confirm your email first (the sign-in link button above), or pick another option.'
                    ));
                }
                $logger->info('registration', 'Monthly Donor selection allowed.', [
                    'event' => $eventId,
                    'basis' => $isComplimentary && !$verdict['yes']
                        ? 'complimentary_register'
                        : $verdict['reason'],
                ]);
            } else {
                // Gate open: the option is offered on the honour system, and
                // each claim is logged for later reconciliation.
                $verdict = $donor->status($email, 0);
                $logger->info('registration', 'Monthly Donor selection claimed while the gate is open.', [
                    'event' => $eventId,
                    'evidence' => $verdict['yes'] ? $verdict['reason'] : MonthlyDonor::REASON_NONE,
                ]);
            }
        }

        $demographicsAsked = RegistrationConfig::enabled('demographics');
        $demographics = $demographicsAsked
            ? DemographicFields::sanitize((array) $req->get_param('demographics'))
            : [];
        $demoOther = $demographicsAsked ? sanitize_text_field((string) $req->get_param('demographics_other')) : '';
        $demographicsOnFile = false;
        if ($demographicsAsked && !$demographics && $identity['constituent_id']) {
            /** @var PrefillResolver $prefillResolver */
            $prefillResolver = $container->get('registration.prefill');
            $existing = $prefillResolver->resolve([
                'source' => (string) $identity['source'],
                'constituent_id' => (int) $identity['constituent_id'],
                'via_session' => (bool) $identity['via_session'],
            ]);
            $demographicsOnFile = !empty($existing['prefill']['demographics']);
        }
        if (RegistrationConfig::required('demographics') && !$demographics && !$demographicsOnFile) {
            return $this->fail(RegistrationWording::text(
                'registration_demographic_error',
                'Please answer the demographic question. “Prefer not to say” is available.'
            ));
        }

        // The optional questions configured under Settings.
        $ageRange = RegistrationConfig::enabled('age')
            ? SurveyFields::sanitizeAge((string) $req->get_param('age_range'))
            : '';
        $heard = RegistrationConfig::enabled('heard')
            ? SurveyFields::sanitizeHeard((array) $req->get_param('heard_about'))
            : [];
        $heardOther = in_array(SurveyFields::HEARD_OTHER, $heard, true)
            ? sanitize_text_field((string) $req->get_param('heard_other'))
            : '';
        $promote = RegistrationConfig::enabled('promote')
            ? SurveyFields::sanitizePromote((string) $req->get_param('promote'))
            : '';
        $relationship = RegistrationConfig::enabled('relationship')
            ? SurveyFields::sanitizeRelationship((array) $req->get_param('relationship'))
            : [];
        if ($ageRange === '' && RegistrationConfig::required('age')) {
            return $this->fail(RegistrationWording::text(
                'registration_age_error',
                'Please select your age range.'
            ));
        }
        if (!$heard && RegistrationConfig::required('heard')) {
            return $this->fail(RegistrationWording::text(
                'registration_heard_error',
                'Please tell us how you heard about this event.'
            ));
        }
        if (in_array(SurveyFields::HEARD_OTHER, $heard, true) && $heardOther === '') {
            return $this->fail(RegistrationWording::text(
                'registration_heard_other_error',
                'You chose Other for how you heard about this event; please specify.'
            ));
        }

        // BosqueGrande's encrypted constituent store works independently
        // of LGL and is populated before mail is composed, so local-mode
        // confirmation links are available on the very first registration.
        if (!$store->available()) {
            $logger->error('registration', 'Encrypted constituent storage is unavailable; registration was refused before personal data could be lost.', [
                'event' => $eventId,
            ]);
            update_option('bg_answer_store_failure', current_time('mysql', true), false);
            return $this->fail(RegistrationWording::text(
                'registration_answer_store_unavailable',
                'We cannot safely save your answers right now. Please try again shortly or contact the organizer.'
            ));
        }

        // Serialize the first email lookup and possible constituent creation.
        // The blind index keeps the option name free of PII. A second request
        // for the same email, even across two events, cannot create a parallel
        // constituent before the first request reaches a stable local id.
        $emailBlindIndex = (string) $container->get('encryption')->blindIndex(
            ConstituentStore::normEmail($email)
        );
        if ($emailBlindIndex === '') {
            return $this->fail(RegistrationWording::text(
                'registration_match_unavailable',
                'We cannot safely match your registration right now. Please try again shortly.'
            ));
        }
        $emailLock = 'bg_reg_email_' . hash('sha256', $emailBlindIndex);
        $emailLockOwner = $this->acquireSubmissionLock($emailLock);
        if ($emailLockOwner === '') {
            return new \WP_REST_Response([
                'ok' => false,
                'message' => RegistrationWording::text(
                    'registration_already_processing',
                    'This registration is already being processed. Please wait a moment before trying again.'
                ),
            ], 409);
        }
        register_shutdown_function(function () use ($emailLock, $emailLockOwner): void {
            $this->releaseSubmissionLock($emailLock, $emailLockOwner);
        });

        $localConstituentId = $store->upsertByEmail($email, [
            'first_name' => $first,
            'last_name' => $last,
            'phone' => sanitize_text_field((string) $req->get_param('phone')),
            'address1' => sanitize_text_field((string) $req->get_param('street')),
            'city' => sanitize_text_field((string) $req->get_param('city')),
            'state' => sanitize_text_field((string) $req->get_param('state')),
            'postal' => sanitize_text_field((string) $req->get_param('zip')),
        ]);
        if (!$localConstituentId) {
            $logger->error('registration', 'A local constituent record could not be created; registration was refused.', [
                'event' => $eventId,
            ]);
            update_option('bg_answer_store_failure', current_time('mysql', true), false);
            return $this->fail(RegistrationWording::text(
                'registration_contact_save_error',
                'We could not safely save your information. Please try again shortly.'
            ));
        }

        /** @var AnswerRetry $answerRetry */
        $answerRetry = $container->get('registration.answer_retry');
        $answersSaved = $answerRetry->saveRequired(
            $localConstituentId,
            $demographics, $demoOther, $ageRange, $relationship,
            $heard, $heardOther, $promote,
            $identity['source'] === 'bg' && (int) $identity['constituent_id'] === $localConstituentId
        );
        if (!$answersSaved) {
            update_option('bg_answer_store_failure', current_time('mysql', true), false);
            $logger->error('registration', 'Mandatory encrypted answers did not verify; registration was refused.', [
                'event' => $eventId,
                'constituent' => $localConstituentId,
            ]);
            return $this->fail(RegistrationWording::text(
                'registration_answer_save_error',
                'We could not safely save your answers. Nothing has been registered yet; please try again.'
            ));
        }

        // Defer newsletter mutation until the registration is known to exist
        // (or an existing valid registration is found). A preference-service
        // problem must never create or destroy an event registration.
        $newsletterSelectionsPresent = (string) $req->get_param('subscriptions_present') === '1';
        $requestedSubscriptionIds = (array) $req->get_param('subscriptions');

        // Retain source ids as sync metadata, but make no CRM request.
        $lglEventId = 0;
        if ((string) get_post_meta($eventId, '_bg_src', true) === 'lgl') {
            $lglEventId = (int) get_post_meta($eventId, '_bg_src_id', true);
        }
        $isFree = Tiers::isFree($tier);
        $invitationId = null;
        // An LGL identity (newsletter link or LGL sign-in) proves nothing about
        // the email typed on this form. Keep its LGL id only when the local
        // record is already linked to it; a public form never creates links.
        if ($identity['source'] === 'lgl' && $lglConstituentId > 0) {
            $linkedRecord = $store->get($localConstituentId);
            if ((int) ($linkedRecord['lgl_constituent_id'] ?? 0) !== $lglConstituentId) {
                $lglConstituentId = 0;
            }
        }

        /** @var StripeClient $stripe */
        $stripe = $container->get('stripe');
        $onlinePayment = !$isFree && $stripe->isConfigured();

        /** @var RegistrationRepository $repo */
        $repo = $container->get('registration.repo');
        $submissionLock = 'bg_reg_submit_' . hash('sha256', $eventId . '|' . $localConstituentId);
        $submissionLockOwner = $this->acquireSubmissionLock($submissionLock);
        if ($submissionLockOwner === '') {
            return new \WP_REST_Response([
                'ok' => false,
                'message' => RegistrationWording::text(
                    'registration_already_processing',
                    'This registration is already being processed. Please wait a moment before trying again.'
                ),
            ], 409);
        }
        register_shutdown_function(function () use ($submissionLock, $submissionLockOwner): void {
            $this->releaseSubmissionLock($submissionLock, $submissionLockOwner);
        });

        $existingRegistration = $repo->latestActiveForEventAndConstituent($eventId, $localConstituentId);
        if ($existingRegistration) {
            $existingStatus = (string) $existingRegistration->status;
            if (in_array($existingStatus, ['confirmed', 'paid'], true)) {
                $newsletterNote = $this->applyNewsletterChoices(
                    $container, $email, $localConstituentId, $confirmedSubscriptionProfileId,
                    $requestedSubscriptionIds, $newsletterSelectionsPresent, $eventId
                );
                return new \WP_REST_Response([
                    'ok' => true,
                    'payment_required' => false,
                    'message' => RegistrationWording::text(
                        'registration_already_registered',
                        'You are already registered for this event. No duplicate registration was created.'
                    ) . $newsletterNote,
                ], 200);
            }
            $existingIntent = (string) ($existingRegistration->stripe_payment_intent ?? '');
            if ($existingStatus === 'pending' && $existingIntent !== '') {
                $existingPi = $stripe->retrievePaymentIntent($existingIntent);
                if (is_array($existingPi)
                    && (int) ($existingPi['metadata']['registration_id'] ?? 0) === (int) $existingRegistration->id
                    && (int) ($existingPi['metadata']['event_post_id'] ?? 0) === $eventId
                    && hash_equals((string) $existingRegistration->tier, (string) ($existingPi['metadata']['tier'] ?? ''))
                    && !empty($existingPi['client_secret'])
                    && !in_array((string) ($existingPi['status'] ?? ''), ['canceled', 'succeeded'], true)
                ) {
                    $newsletterNote = $this->applyNewsletterChoices(
                        $container, $email, $localConstituentId, $confirmedSubscriptionProfileId,
                        $requestedSubscriptionIds, $newsletterSelectionsPresent, $eventId
                    );
                    return new \WP_REST_Response([
                        'ok' => true,
                        'payment_required' => true,
                        'client_secret' => (string) $existingPi['client_secret'],
                        'message' => RegistrationWording::text(
                            'registration_existing_payment_ready',
                            'Your existing registration is ready for payment; no duplicate was created.'
                        ) . $newsletterNote,
                    ], 200);
                }
            }
            $age = time() - (int) strtotime((string) ($existingRegistration->updated_at ?? 'now') . ' UTC');
            if ($existingStatus === 'pending' && $existingIntent === '' && $age > 5 * MINUTE_IN_SECONDS) {
                $repo->setStatus((int) $existingRegistration->id, 'failed');
            } else {
                return new \WP_REST_Response([
                    'ok' => false,
                    'message' => RegistrationWording::text(
                        'registration_existing_finalizing',
                        'An existing registration is still being finalized. Please wait a moment and refresh before trying again.'
                    ),
                ], 409);
            }
        }
        $regId = $repo->insert([
            'event_post_id' => $eventId,
            'lgl_event_id' => $lglEventId ?: null,
            'lgl_constituent_id' => $lglConstituentId ?: null,
            'bg_constituent_id' => $localConstituentId ?: null,
            'tier' => $tier,
            'amount_cents' => Tiers::amount($tier),
            'status' => $onlinePayment ? 'pending' : 'confirmed',
            'volunteer_interest' => Tiers::volunteerChain($tier) || (bool) $req->get_param('volunteer_interest'),
            'lgl_invitation_id' => $invitationId,
            'is_new_constituent' => !$wasExistingLgl && $lglConstituentId ? 1 : 0,
        ]);

        $logger->info('registration', 'Registration recorded.', [
            'registration' => $regId,
            'event' => $eventId,
            'tier' => $tier,
        ]);
        if ($regId < 1) {
            $logger->error('registration', 'Registration row could not be created.', [
                'event' => $eventId,
                'tier' => $tier,
            ]);
            return $this->fail(RegistrationWording::text(
                'registration_record_error',
                'We could not record the registration. Please try again in a moment.'
            ));
        }

        // Paid tier with all Stripe pieces configured: hand back a PaymentIntent.
        if ($onlinePayment) {
            $paymentDescription = \BosqueGrande\Core\MailText::line(
                RegistrationWording::format(
                    'registration_payment_description',
                    'Event registration: {event}',
                    ['event' => (string) get_the_title($event)]
                )
            );
            $pi = $stripe->createPaymentIntent(
                Tiers::amount($tier),
                $paymentDescription,
                [
                    'registration_id' => (string) $regId,
                    'event_post_id' => (string) $eventId,
                    'lgl_event_id' => (string) $lglEventId,
                    'constituent_id' => (string) $lglConstituentId,
                    'bg_constituent_id' => (string) $localConstituentId,
                    'tier' => $tier,
                    'email' => $email,
                    'first_name' => $first,
                    // Event-level survey answers ride to the webhook, which
                    // writes them onto the LGL invitation note (never at
                    // rest in WordPress). Stripe metadata caps at 500 chars.
                    'survey_heard' => substr(implode(', ', $heard), 0, 450),
                    'survey_heard_other' => substr($heardOther, 0, 190),
                    'survey_promote' => substr($promote, 0, 190),
                ]
            );
            if (!$pi) {
                $repo->setStatus($regId, 'failed');
                return $this->fail(RegistrationWording::text(
                    'registration_payment_start_error',
                    'We could not start the payment. Please try again in a moment.'
                ));
            }
            if (!$repo->setPaymentIntent($regId, (string) ($pi['id'] ?? ''))) {
                $logger->error('registration', 'PaymentIntent created but could not be bound to its registration row.', [
                    'registration' => $regId,
                ]);
                $repo->setStatus($regId, 'failed');
                return $this->fail(RegistrationWording::text(
                    'registration_payment_link_error',
                    'We could not safely link the payment to your registration. Please try again in a moment.'
                ));
            }
            $newsletterNote = $this->applyNewsletterChoices(
                $container, $email, $localConstituentId, $confirmedSubscriptionProfileId,
                $requestedSubscriptionIds, $newsletterSelectionsPresent, $eventId
            );
            return new \WP_REST_Response([
                'ok' => true,
                'payment_required' => true,
                'client_secret' => (string) $pi['client_secret'],
                'message' => RegistrationWording::text(
                    'registration_payment_prompt',
                    'Almost there: enter payment below to confirm your spot.'
                ) . $newsletterNote,
            ], 200);
        }

        $newsletterNote = $this->applyNewsletterChoices(
            $container, $email, $localConstituentId, $confirmedSubscriptionProfileId,
            $requestedSubscriptionIds, $newsletterSelectionsPresent, $eventId
        );
        $mailAccepted = $this->sendConfirmation(
            $container,
            $eventId,
            $email,
            $first,
            $tier,
            $localConstituentId
        );

        $mailNote = $mailAccepted
            ? RegistrationWording::suffix('registration_mail_accepted_note', 'A confirmation email has been submitted for delivery.')
            : RegistrationWording::suffix('registration_mail_failed_note', 'Your registration is saved, but the confirmation email could not be sent; please contact the organizer if you need a written copy.');
        $msg = RegistrationWording::format(
            'registration_confirmed_message',
            'Thanks, {first}! Your registration is confirmed.',
            ['first' => $first]
        ) . $mailNote . $newsletterNote;
        if (!$isFree) {
            // A paid tier reaches this point only when online payment is
            // not available (Stripe keys not yet saved). Say that plainly
            // rather than promising a door that may not exist; the spot
            // is held and the organizer will arrange payment.
            $msg = RegistrationWording::format(
                'registration_offline_reserved_message',
                'Thanks, {first}! Your spot is reserved. This event has a fee and online payment is not set up yet, so the organizer will follow up about payment.',
                ['first' => $first]
            ) . $mailNote . $newsletterNote;
        }
        if (Tiers::volunteerChain($tier)) {
            $msg .= RegistrationWording::suffix(
                'registration_volunteer_note',
                'The organizer has your volunteer interest and will follow up directly.'
            );
        }
        return $this->ok($msg);
    }

    /**
     * Apply newsletter choices only after a valid registration outcome.
     * Verified sessions may reconcile check/uncheck state. Public or Tier 1
     * forms can only request signed confirmation links for positive choices.
     * A failure is reported separately and never rolls back event attendance.
     *
     * @param array<int, mixed> $requestedIds
     */
    private function applyNewsletterChoices(
        Container $container,
        string $email,
        int $localConstituentId,
        int $confirmedProfileId,
        array $requestedIds,
        bool $present,
        int $eventId
    ): string {
        if (!$present || !$container->has('correspondence.groups') || $localConstituentId < 1) return '';
        $groups = $container->get('correspondence.groups');
        $validIds = $groups->validActiveSelectionIds($requestedIds);
        $allowUnsubscribe = $confirmedProfileId > 0 && $confirmedProfileId === $localConstituentId;

        if ($allowUnsubscribe) {
            $emailBidx = $container->get('encryption')->blindIndex(ConstituentStore::normEmail($email));
            $suppressionReason = $groups->suppressionReason($emailBidx);
            $deliveryRestricted = $suppressionReason !== null && $suppressionReason !== 'unsubscribe-all';
            $saved = $groups->saveActiveSelections(
                $localConstituentId,
                $validIds,
                'event_registration',
                true,
                !$deliveryRestricted
            );
            if ($saved && $validIds && !$deliveryRestricted) {
                $saved = $groups->clearUserUnsubscribeAll(
                    $emailBidx,
                    $localConstituentId,
                    'event_registration'
                );
            }
            if (!$saved) {
                $container->get('logger')->error('registration', 'Verified newsletter choices could not be saved; event registration continued.', [
                    'event' => $eventId,
                    'constituent' => $localConstituentId,
                ]);
                return RegistrationWording::suffix(
                    'registration_newsletter_save_failed',
                    'Your event registration is saved, but newsletter choices could not be updated; please use My Profile later.'
                );
            }
            if ($deliveryRestricted) {
                return RegistrationWording::suffix(
                    'registration_newsletter_restricted',
                    'Your event registration is saved. This email has a newsletter delivery restriction that cannot be changed here; contact {org} if you believe it is an error.'
                );
            }
            return RegistrationWording::suffix(
                'registration_newsletter_saved',
                'Your newsletter choices were saved.'
            );
        }

        if (!$validIds) return '';
        $accepted = \BosqueGrande\Modules\Correspondence\CorrespondenceModule::requestSubscriptionConfirmations(
            $container,
            $email,
            $validIds,
            'event_registration',
            $localConstituentId
        );
        if (!$accepted) {
            $container->get('logger')->error('registration', 'Newsletter confirmation request was not accepted; event registration continued.', [
                'event' => $eventId,
                'groups' => count($validIds),
            ]);
        }
        // Neutral wording does not disclose whether this email address is
        // already subscribed, suppressed, or unknown.
        return RegistrationWording::suffix(
            'registration_newsletter_confirmation',
            'If confirmation is needed for your newsletter choices, check your email for a separate confirmation link.'
        );
    }

    private function onComplimentaryRegister(Container $container, string $email): bool
    {
        $list = (string) $container->get('settings')->get('complimentary_register', '');
        if ($list === '') return false;
        $emails = array_filter(array_map(
            fn($line) => strtolower(trim($line)),
            preg_split('/[\r\n,;]+/', $list) ?: []
        ));
        return in_array(strtolower(trim($email)), $emails, true);
    }

    /**
     * Create or update the constituent with contact and demographic
     * data. Demographic keywords write to the configured LGL category.
     * NOTE: verify the categories write shape against the live account
     * Values are never logged.
     *
     * @param array<string, mixed> $d
     */
    private function upsertConstituent(LglClient $lgl, int $constituentId, array $d): int
    {
        $payload = [
            'first_name' => $d['first_name'],
            'last_name' => $d['last_name'],
            'email_addresses' => [['address' => $d['email'], 'email_address_type_id' => 1]],
        ];
        if ($d['phone'] !== '') {
            $payload['phone_numbers'] = [['number' => $d['phone'], 'phone_number_type_id' => 1]];
        }
        if ($d['street'] !== '' || $d['city'] !== '') {
            $payload['street_addresses'] = [[
                'street' => $d['street'],
                'city' => $d['city'],
                'state' => $d['state'],
                'postal_code' => $d['zip'],
                'street_address_type_id' => 1,
            ]];
        }
        $keywords = $d['demographics'];
        if (in_array(DemographicFields::OTHER_KEY, $keywords, true) && $d['demographics_other'] !== '') {
            $keywords[] = 'Other: ' . $d['demographics_other'];
        }
        $categories = [];
        if ($keywords) {
            $categories[] = [
                'name' => (string) apply_filters('bg_demographic_lgl_category', 'Demographics'),
                'keywords' => array_map(fn($k) => ['name' => $k], $keywords),
            ];
        }
        if (($d['age_range'] ?? '') !== '') {
            $categories[] = [
                'name' => (string) apply_filters('bg_age_range_lgl_category', 'Age Range'),
                'keywords' => [['name' => $d['age_range']]],
            ];
        }
        if (!empty($d['relationship'])) {
            $categories[] = [
                'name' => (string) apply_filters('bg_relationship_lgl_category', 'Relationship Status'),
                'keywords' => array_map(fn($k) => ['name' => $k], $d['relationship']),
            ];
        }
        if ($categories) {
            // Write each answer to the structure this account
            // actually uses (category or custom attribute), with real
            // ids resolved and missing keywords created on the way.
            $cached = $constituentId > 0 ? get_transient('bg_pf_lglr2_' . $constituentId) : false;
            $payload = array_merge(
                $payload,
                $lgl->answerPayload($categories, is_array($cached) ? $cached : null)
            );
        }

        if ($constituentId) {
            return $lgl->updateConstituent($constituentId, $payload) !== null
                ? $constituentId : 0;
        }
        $created = $lgl->createConstituent($payload);
        return $created ? (int) ($created['id'] ?? 0) : 0;
    }

    private function sendConfirmation(
        Container $container,
        int $eventId,
        string $email,
        string $first,
        string $tier,
        int $constituentId
    ): bool
    {
        $eventTitle = \BosqueGrande\Core\MailText::line((string) get_the_title($eventId));
        $body = ConfirmationEmail::body(
            $container,
            $eventId,
            $first,
            $tier,
            $email,
            $constituentId
        );
        $sent = wp_mail($email, ConfirmationEmail::subject($eventId), $body);
        $container->get('logger')->info('registration', $sent ? 'Confirmation dispatched.' : 'Confirmation dispatch refused (wp_mail).', []);
        // The local record is the system of record.
        do_action('bg_registration_completed', $email, $first, $eventTitle, $tier, $constituentId);
        return (bool) $sent;
    }

    /**
     * The prefill bootstrap: a single-tap "save my info" link in every
     * confirmation email. One tap starts the 12-month device session, so
     * the first manual registration is also the last one. Empty string
     * when no constituent record exists to link.
     */
    public static function saveInfoParagraph(Container $container, string $email, int $localConstituentId): string
    {
        if (!is_email($email)) return '';
        $source = 'bg';
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        // The link signs its holder in as this record, so the record must own
        // the address the email is going to.
        if (!$store->available() || $localConstituentId < 1
            || !in_array($localConstituentId, $store->idsByEmail($email), true)
        ) return '';
        $record = $store->get($localConstituentId);
        $constituentId = $record && ($record['status'] ?? '') === 'active'
            ? $localConstituentId
            : 0;
        if (!$constituentId) return '';

        /** @var \BosqueGrande\Modules\Identity\TokenRepository $tokens */
        $tokens = $container->get('identity.tokens');
        $raw = $tokens->createMagic($email, $constituentId, 7 * DAY_IN_SECONDS, $source);
        if ($raw === '') {
            $container->get('logger')->error('registration', 'Save-my-information link omitted because its token could not be stored.');
            return '';
        }
        $link = add_query_arg('bg_verify', $raw, home_url('/'));
        $invitation = RegistrationWording::format(
            'registration_email_save_info_body',
            "----\nTired of retyping your info? Tap once to save it on this device,\n"
                . "and every future registration form fills itself in automatically:\n{link}",
            ['link' => $link],
            ['link']
        );
        return "\n" . trim($invitation) . "\n";
    }

    /** @return array{source: string, constituent_id: int, via_session: bool} */
    private function currentIdentity(Container $container): array
    {
        $identity = apply_filters('bg_current_identity', [
            'source' => '', 'constituent_id' => 0, 'via_session' => false,
        ]);
        if (is_array($identity) && !empty($identity['constituent_id'])
            && in_array((string) ($identity['source'] ?? ''), ['bg', 'lgl'], true)
        ) {
            return [
                'source' => (string) $identity['source'],
                'constituent_id' => (int) $identity['constituent_id'],
                'via_session' => !empty($identity['via_session']),
            ];
        }
        return ['source' => '', 'constituent_id' => 0, 'via_session' => false];
    }

    private function rateLimited(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        return !$this->claimRegistrationBudget('ip:' . hash('sha256', $ip), 5, 10 * MINUTE_IN_SECONDS)
            || !$this->claimRegistrationBudget('global', 120, 10 * MINUTE_IN_SECONDS);
    }

    /** Return an owner token for a stale-recoverable, compare-and-release lock. */
    private function acquireSubmissionLock(string $key): string
    {
        return OptionLease::acquire($key, 15 * MINUTE_IN_SECONDS);
    }

    private function releaseSubmissionLock(string $key, string $owner): void
    {
        OptionLease::release($key, $owner);
    }

    private function claimRegistrationBudget(string $scope, int $limit, int $window): bool
    {
        $hash = md5($scope);
        $lock = 'bg_reg_limit_lock_' . $hash;
        $owner = OptionLease::acquire($lock, 10);
        if ($owner === '') return false;
        try {
            $key = 'bg_reg_limit_' . $hash;
            $state = get_transient($key);
            $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
            if ($count >= $limit) return false;
            return set_transient($key, ['count' => $count + 1], max(1, $window));
        } finally {
            OptionLease::release($lock, $owner);
        }
    }

    private function ok(string $message): \WP_REST_Response
    {
        return new \WP_REST_Response(['ok' => true, 'message' => $message], 200);
    }

    private function fail(string $message): \WP_REST_Response
    {
        return new \WP_REST_Response(['ok' => false, 'message' => $message], 400);
    }

    private function css(): string
    {
        return <<<'CSS'
.bg-reg-wrap{max-width:720px;margin:0 auto;padding:0;font-family:'Inter',-apple-system,sans-serif;color:#1F2A24}
.bg-reg{background:#fff;border:1px solid #D9E5D9;border-radius:16px;padding:28px 26px 26px;position:relative;box-shadow:0 2px 10px rgba(31,42,36,.06)}
.bg-reg::before{content:"";position:absolute;top:0;left:0;right:0;height:5px;border-radius:16px 16px 0 0;background:linear-gradient(90deg,#1F5130 0%,#2E7D4F 70%,#C98A1B 100%)}
.bg-reg-title{color:var(--bgc-primary,#1F5130);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:clamp(1.55rem,4vw,2.15rem);font-weight:750;line-height:1.16;letter-spacing:-.025em;text-transform:none;margin:4px 0 6px}
.bg-reg-ended{max-width:680px}.bg-reg-status{display:inline-block;margin:0 0 10px;padding:5px 10px;border-radius:999px;background:#F1F4F1;color:#536158;font-size:.75rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.bg-reg-ended .bg-reg-when{padding:11px 13px;border-left:4px solid #8B988E;border-radius:7px;background:#F5F7F5}.bg-reg-ended .bg-ended-return{display:inline-block;margin-top:8px;color:#fff;text-decoration:none}.bg-reg-ended .bg-ended-return:hover{color:#fff}
.bg-return-row{margin:0 0 12px}.bg-return-calendar{color:#1F5130;font-size:.9rem;font-weight:700;text-decoration:none}.bg-return-calendar:hover{text-decoration:underline}
.bg-reg-when{color:#5A6A61;margin-top:0}
.bg-fieldset{border:1px solid #D9E5D9;border-radius:12px;padding:18px 16px;margin:18px 0;background:#FBFDFB}
.bg-fieldset legend{font-weight:700;padding:0 8px;color:#1F5130}
.bg-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.bg-grid label{display:flex;flex-direction:column;font-size:.9rem;font-weight:600;gap:5px;color:#3C4A42}
.bg-grid input{padding:10px 11px;border:1px solid #C4D6C4;border-radius:9px;font-size:1rem;background:#fff;transition:border-color .12s ease}
.bg-fieldset input[type=text],.bg-fieldset input[type=email],.bg-fieldset input[type=tel],.bg-fieldset select{max-width:100%;padding:10px 11px;border:1px solid #C4D6C4;border-radius:9px;background:#fff;color:#1F2A24;font:400 1rem/1.4 Inter,-apple-system,sans-serif}
.bg-fieldset select{width:100%}.bg-fieldset input:focus-visible,.bg-fieldset select:focus-visible{outline:2px solid #2E7D4F;outline-offset:1px;border-color:#2E7D4F}
.bg-grid input:focus-visible{outline:2px solid #2E7D4F;outline-offset:1px;border-color:#2E7D4F}.bg-nudge input:focus-visible{outline:2px solid #2E7D4F;outline-offset:1px;border-color:#2E7D4F}
.bg-wide{grid-column:1/-1}
.bg-demo-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:8px}
.bg-check{display:flex;align-items:center;gap:8px;font-size:.92rem;color:#1F2A24}
.bg-check input{accent-color:#1F5130}
.bg-subscription-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px 16px;margin-top:12px}.bg-subscription-option{align-items:flex-start}.bg-subscription-option small{color:#4B5A4B}
.bg-tiers{display:flex;flex-direction:column;gap:10px}
.bg-tier{display:flex;flex-wrap:wrap;align-items:center;gap:10px;border:1.5px solid #C4D6C4;border-radius:12px;padding:12px 14px;cursor:pointer;background:#fff;transition:border-color .12s ease,background .12s ease}
.bg-tier:hover{border-color:#2E7D4F}
.bg-tier:has(input:checked){border-color:#1F5130;background:#F2F7F2;box-shadow:inset 0 0 0 1px #1F5130}
.bg-tier input{accent-color:#1F5130}
.bg-tier-locked{opacity:.55}
.bg-tier-label{font-weight:700}
.bg-donor-cta{margin:6px 0 14px}.bg-donor-buttons{display:inline-grid;gap:8px;max-width:100%}.bg-donor-btn{display:block;box-sizing:border-box;padding:11px 26px;border-radius:7px;color:#fff!important;font-weight:700;text-align:center;text-decoration:none;background:linear-gradient(135deg,var(--bgc-donor-a,#1F5130),var(--bgc-donor-b,#2E7D4F));box-shadow:0 2px 8px rgba(31,81,48,.35)}.bg-donor-btn-additional{background:#0B2A4A;box-shadow:0 2px 8px rgba(11,42,74,.35)}.bg-donor-btn:hover{filter:brightness(1.12)}
.bg-btn{background:linear-gradient(135deg,#2A6B40,#1F5130);color:#fff;font-weight:700;border:0;border-radius:999px;padding:13px 28px;font-size:1rem;cursor:pointer;transition:transform .12s ease,box-shadow .12s ease;box-shadow:0 3px 10px rgba(31,81,48,.25)}
.bg-btn:hover{transform:translateY(-1px);box-shadow:0 5px 14px rgba(31,81,48,.3)}
.bg-btn:focus-visible{outline:2px solid #C98A1B;outline-offset:2px}
.bg-btn-secondary{background:#1F5130;padding:9px 18px;font-size:.9rem;box-shadow:none}
.bg-link{background:none;border:0;color:#1F5130;text-decoration:underline;cursor:pointer;font-size:inherit;padding:0}
.bg-link:focus-visible{outline:2px solid #2E7D4F;outline-offset:1px}
.bg-muted{color:#5A6A61;font-size:.85rem;font-weight:400}
.bg-required{color:#8B1E1E;font-size:.85rem;font-weight:700}
.bg-onfile{background:#F2F7F2;border:1px solid #BFDCC2;border-radius:10px;padding:11px 13px;color:#1F5130}
.bg-nudge{border:2px solid transparent;border-radius:14px;padding:14px 16px;margin-bottom:10px;background:linear-gradient(#FBFDFB,#FBFDFB) padding-box,linear-gradient(135deg,#2E7D4F,#E0701A) border-box;box-shadow:0 2px 10px rgba(107,63,160,.12)}.bg-nudge-hl{background:rgba(224,112,26,.16);padding:2px 7px;border-radius:6px;font-weight:700}.bg-nudge-cta{color:#2E7D4F;font-weight:700;text-decoration:underline;text-decoration-color:#E0701A;text-decoration-thickness:2px;text-underline-offset:3px}.bg-nudge-cta:hover{color:#E0701A;text-decoration-color:#2E7D4F}
.bg-nudge input{padding:9px 11px;border:1px solid #C4D6C4;border-radius:9px;margin:8px 8px 0 0}
.bg-hp{display:none!important}
#bg-reg-msg{font-weight:600;margin-top:12px;color:#1F5130}
#bg-payment{margin-top:14px}
@media(max-width:640px){.bg-grid,.bg-demo-grid,.bg-subscription-grid{grid-template-columns:minmax(0,1fr)}.bg-reg{padding:22px 16px 20px}}
@media(prefers-reduced-motion:reduce){.bg-btn,.bg-tier,.bg-grid input{transition:none}.bg-btn:hover{transform:none}}
CSS;
    }

    private function js(): string
    {
        $restBase = trailingslashit(esc_url_raw(rest_url('bosquegrande/v1')));
        $paymentTrust = PaymentTrust::embedded(RegistrationWording::text(
            'registration_payment_trust_intro',
            'The card-entry fields below are secure Stripe fields for this event registration.'
        ));
        $wording = [
            'invalidEmail' => RegistrationWording::text('registration_js_invalid_email', 'Please enter a valid email address.'),
            'sendingLink' => RegistrationWording::text('registration_js_sending_link', 'Sending…'),
            'checkInbox' => RegistrationWording::text('registration_js_check_inbox', 'Check your inbox.'),
            'generalError' => RegistrationWording::text('registration_js_general_error', 'Something went wrong. Please try again.'),
            'paymentButton' => RegistrationWording::text('registration_js_payment_button', 'Pay securely with Stripe and confirm'),
            'paymentProcessing' => RegistrationWording::text('registration_js_payment_processing', 'Processing securely with Stripe...'),
            'paymentFailed' => RegistrationWording::text('registration_js_payment_failed', 'Payment failed. Please try again.'),
            'paymentFinalizing' => RegistrationWording::text('registration_js_payment_finalizing', 'Payment received. Finalizing your registration...'),
            'paymentConfirmed' => RegistrationWording::text('registration_js_payment_confirmed', 'Payment received and your registration is confirmed. A confirmation email is on its way.'),
            'paymentPending' => RegistrationWording::text('registration_js_payment_pending', 'Payment received. Your registration is being finalized; the confirmation should follow shortly.'),
            'sendingRegistration' => RegistrationWording::text('registration_js_sending_registration', 'Sending...'),
            'staleReload' => RegistrationWording::text('registration_js_stale_reload', 'This form has been open a while. Please reload the page & submit again.'),
            'demographicError' => RegistrationWording::text('registration_demographic_error', 'Please answer the demographic question. “Prefer not to say” is available.'),
        ];
        return str_replace(
            ['__BG_REST_BASE__', '__BG_PAYMENT_TRUST__', '__BG_WORDING__'],
            [wp_json_encode($restBase), wp_json_encode($paymentTrust), wp_json_encode($wording)],
            <<<'JS'
(function(){
  var f=document.getElementById('bg-reg-form');if(!f)return;
  var restBase=__BG_REST_BASE__;
  var words=__BG_WORDING__;
  var msg=document.getElementById('bg-reg-msg');
  var vol=document.getElementById('bg-volunteer');
  var other=document.getElementById('bg-demo-other');
  f.addEventListener('change',function(e){
    if(e.target.name==='tier'&&vol){vol.hidden=e.target.getAttribute('data-volunteer')==='1';}
    if(e.target.name==='demographics[]'&&other){
      var checked=f.querySelector('input[name="demographics[]"][value="Other"]');
      other.hidden=!(checked&&checked.checked);
    }
    if(e.target.name==='demographics[]'&&e.target.checked){
      var decline=f.querySelector('input[name="demographics[]"][value="Prefer not to say"]');
      if(e.target.value==='Prefer not to say'){
        f.querySelectorAll('input[name="demographics[]"]').forEach(function(input){
          if(input!==e.target){input.checked=false;}
        });
        if(other){other.hidden=true;}
      }else if(decline){
        decline.checked=false;
      }
    }
    if(e.target.name==='heard_about[]'){
      var ho=document.getElementById('bg-heard-other');
      var hc=f.querySelector('input[name="heard_about[]"][value="Other"]');
      if(ho){ho.hidden=!(hc&&hc.checked);}
    }
  });
  var exp=document.getElementById('bg-demo-expand');
  if(exp){exp.addEventListener('click',function(){
    document.getElementById('bg-demo-fields').hidden=false;
    exp.closest('.bg-onfile').hidden=true;
  });}
  var nb=document.getElementById('bg-nudge-btn');
  if(nb){nb.addEventListener('click',function(){
    var nf=document.getElementById('bg-nudge-form');
    var ne=document.getElementById('bg-nudge-email');
    var re=f.querySelector('input[name="email"]');
    var nfirst=document.getElementById('bg-nudge-first');
    var nlast=document.getElementById('bg-nudge-last');
    var rfirst=f.querySelector('input[name="first_name"]');
    var rlast=f.querySelector('input[name="last_name"]');
    if(nf)nf.hidden=false;
    if(ne&&re&&!ne.value)ne.value=re.value;
    if(nfirst&&rfirst&&!nfirst.value)nfirst.value=rfirst.value;
    if(nlast&&rlast&&!nlast.value)nlast.value=rlast.value;
    nb.disabled=true;
  });}
  var ns=document.getElementById('bg-nudge-send');
  if(ns){ns.addEventListener('click',function(){
    var input=document.getElementById('bg-nudge-email');
    var out=document.getElementById('bg-nudge-msg');
    var em=input?input.value.trim():'';
    if(!input||!input.checkValidity()){
      if(out)out.textContent=words.invalidEmail;
      if(input)input.reportValidity();
      return;
    }
    ns.disabled=true;
    if(out)out.textContent=words.sendingLink;
    var first=document.getElementById('bg-nudge-first');
    var last=document.getElementById('bg-nudge-last');
    var body=new FormData();body.append('email',em);body.append('redirect',location.href);
    body.append('first_name',first?first.value.trim():'');body.append('last_name',last?last.value.trim():'');
    fetch(restBase+'identity/request-link',{method:'POST',body:body,cache:'no-store',credentials:'same-origin'})
      .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
      .then(function(d){if(out)out.textContent=d.message||words.checkInbox;})
      .catch(function(){if(out)out.textContent=words.generalError;})
      .then(function(){ns.disabled=false;});
  });}
  if(window.BG_STRIPE_PK){
    f.querySelectorAll('[data-bg-paid-note]').forEach(function(el){el.textContent='';});
  }
  function startPayment(secret){
    var stripe=Stripe(window.BG_STRIPE_PK);
    var elements=stripe.elements({clientSecret:secret});
    var wrap=document.createElement('div');
    wrap.id='bg-payment';
    wrap.innerHTML=__BG_PAYMENT_TRUST__+'<div id="bg-payment-el"></div><button type="button" class="bg-btn" id="bg-pay-btn" style="margin-top:12px"></button>';
    f.appendChild(wrap);
    elements.create('payment').mount('#bg-payment-el');
    var paymentButton=document.getElementById('bg-pay-btn');
    paymentButton.textContent=words.paymentButton;
    paymentButton.addEventListener('click',function(){
      this.disabled=true;msg.textContent=words.paymentProcessing;
      stripe.confirmPayment({elements:elements,redirect:'if_required',
        confirmParams:{return_url:location.href}})
        .then(function(res){
          if(res.error){msg.textContent=res.error.message||words.paymentFailed;
            document.getElementById('bg-pay-btn').disabled=false;return;}
          wrap.hidden=true;
          msg.textContent=words.paymentFinalizing;
          /* Finalize server-side. On webhook sites this is a no-op the
             webhook already did; without a webhook it is what marks the
             registration paid. The server re-checks the intent with
             Stripe, so this call cannot fake a payment. */
          var pid=(res.paymentIntent&&res.paymentIntent.id)?res.paymentIntent.id:'';
          var cb=new FormData();cb.append('payment_intent',pid);
          fetch(restBase+'confirm-payment',{method:'POST',body:cb,cache:'no-store',credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(d){
              msg.textContent=(d&&d.ok)
                ?words.paymentConfirmed
                :words.paymentPending;
            })
            .catch(function(){
              msg.textContent=words.paymentPending;
            });
        });
    });
  }
  function sendReg(retried){
    var btn=document.getElementById('bg-submit');btn.disabled=true;msg.textContent=words.sendingRegistration;
    fetch(restBase+'register',{method:'POST',body:new FormData(f),cache:'no-store',credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d.ok&&d.code==='stale_form'&&!retried){
          /* Self-heal: the page outlived its ticket. Fetch a fresh one and resubmit once, silently. */
          var ev=f.querySelector('input[name=event]');
          return fetch(restBase+'reg-nonce?event='+encodeURIComponent(ev?ev.value:''),{cache:'no-store',credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(n){
              if(n.ok&&n.nonce){f.querySelector('input[name=nonce]').value=n.nonce;sendReg(true);}
              else{msg.textContent=words.staleReload;btn.disabled=false;}
            });
        }
        msg.textContent=d.message||'';
        if(d.ok&&d.payment_required&&window.BG_STRIPE_PK){
          f.querySelectorAll('fieldset,button[type=submit]').forEach(function(el){el.hidden=true;});
          startPayment(d.client_secret);
        } else if(d.ok){
          f.querySelectorAll('fieldset,button[type=submit]').forEach(function(el){el.hidden=true;});
        } else{btn.disabled=false;}
      })
      .catch(function(){msg.textContent=words.generalError;btn.disabled=false;});
  }
  f.addEventListener('submit',function(e){
    e.preventDefault();
    var selected=f.querySelector('input[name="demographics[]"]:checked');
    if(f.dataset.demographicsRequired==='1'&&!selected&&f.dataset.demographicsOnFile!=='1'){
      msg.textContent=words.demographicError;
      var first=f.querySelector('input[name="demographics[]"]');
      if(first){first.focus();}
      return;
    }
    sendReg(false);
  });
})();
JS
        );
    }
}
