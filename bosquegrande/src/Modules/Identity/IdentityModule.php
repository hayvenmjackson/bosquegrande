<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Identity;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\Logger;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Modules\Lgl\LglClient;

if (!defined('ABSPATH')) exit;

/**
 * Identity module (ADR-3). Wires:
 *   Tier 2: POST bosquegrande/v1/identity/request-link (rate limited,
 *           enumeration-safe) and ?bg_verify= landing that consumes
 *           the magic token and starts the 12-month device session.
 *   Tier 1: ?bt= resolution on any page (register links) exposing the
 *           constituent id to the Registration Engine via filter, plus
 *           token provisioning for LGL newsletter merges.
 * Verification is an accelerator, never a gate.
 */
final class IdentityModule implements ModuleInterface
{
    public function name(): string
    {
        return 'identity';
    }

    public function label(): string
    {
        return 'Identity (verification and sessions)';
    }

    public function activate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        add_filter('bg_wording_defaults', [self::class, 'wordingDefaults']);

        $container->set('identity.tokens', fn() => new TokenRepository());
        $container->set('identity.session', fn(Container $c) => new Session($c->get('encryption')));
        $container->set('identity.magic', fn(Container $c) => new MagicLink(
            $c->get('identity.tokens'),
            $c->get('identity.session'),
            $c->get('constituents.store'),
            $c->get('settings'),
            $c->get('logger'),
        ));

        add_action('rest_api_init', function () use ($container) {
            register_rest_route('bosquegrande/v1', '/identity/request-link', [
                'methods' => 'POST',
                'permission_callback' => '__return_true', // Public by design; rate limited inside.
                'callback' => function (\WP_REST_Request $req) use ($container) {
                    /** @var MagicLink $magic */
                    $magic = $container->get('identity.magic');
                    return $magic->handleRequestLink(
                        (string) $req->get_param('email'),
                        (string) $req->get_param('redirect'),
                        (string) $req->get_param('first_name'),
                        (string) $req->get_param('last_name')
                    );
                },
            ]);
        });

        // Tier 2 verification landing. GET displays a confirmation page so
        // mail-security scanners cannot consume the one-use secret. Only the
        // visitor's deliberate POST starts a device session.
        add_action('template_redirect', function () use ($container) {
            $isPost = isset($_POST['bg_verify_confirm']);
            $token = $isPost
                ? sanitize_text_field((string) wp_unslash($_POST['bg_verify_confirm']))
                : (isset($_GET['bg_verify']) ? sanitize_text_field((string) wp_unslash($_GET['bg_verify'])) : '');
            if ($token === '') return;
            if (!preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
                wp_safe_redirect(add_query_arg('bg_identity', 'expired', home_url('/')));
                exit;
            }
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            $to = $isPost
                ? (string) wp_unslash($_POST['bg_to'] ?? '')
                : (string) wp_unslash($_GET['bg_to'] ?? '');
            /** @var MagicLink $magic */
            $magic = $container->get('identity.magic');
            // The confirm POST must come from the prompt page in this same
            // browser. The prompt sets a SameSite=Strict cookie that a form
            // posted from another site never carries, so a hostile page
            // cannot sign a visitor into someone else's session.
            $promptProof = self::verifyPromptProof($container, $token);
            if (!$isPost || $promptProof === ''
                || !hash_equals($promptProof, (string) ($_COOKIE[self::PROMPT_COOKIE] ?? ''))
            ) {
                $this->renderVerificationPrompt($token, $to, $promptProof);
            }
            setcookie(self::PROMPT_COOKIE, '', [
                'expires' => time() - HOUR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(),
                'httponly' => true, 'samesite' => 'Strict',
            ]);
            $magic->handleVerify($token, $to);
        }, 5);

        // Post-verification landing. The consumed secret never appears
        // here; the screen is a plain, cache-proof explanation of what
        // the successful device session means before the visitor
        // continues to registration or My Profile.
        add_action('template_redirect', function (): void {
            $state = isset($_GET['bg_identity'])
                ? sanitize_key((string) wp_unslash($_GET['bg_identity']))
                : '';
            if (!in_array($state, ['confirmed', 'expired', 'failed'], true)) return;
            $this->renderVerificationResult($state);
        }, 6);

        // Resolve the current identity with its source. Tier 1 ?bt=
        // links are always LGL; Tier 2 device sessions may be LGL or BG.
        $resolveIdentity = function () use ($container): array {
            $bt = isset($_GET['bt']) ? sanitize_text_field((string) $_GET['bt']) : '';
            if ($bt !== '') {
                /** @var TokenRepository $tokens */
                $tokens = $container->get('identity.tokens');
                $resolved = $tokens->resolveTier1($bt);
                if ($resolved) {
                    return ['source' => 'lgl', 'constituent_id' => $resolved, 'via_session' => false];
                }
            }
            /** @var Session $session */
            $session = $container->get('identity.session');
            $identity = $session->identity();
            if ($identity) {
                return [
                    'source' => $identity['source'],
                    'constituent_id' => $identity['constituent_id'],
                    'via_session' => true,
                ];
            }
            return ['source' => '', 'constituent_id' => 0, 'via_session' => false];
        };

        add_filter('bg_current_identity', function ($identity) use ($resolveIdentity) {
            if (is_array($identity) && !empty($identity['constituent_id'])) return $identity;
            return $resolveIdentity();
        });

        // Housekeeping: purge dead magic tokens daily.
        if (!wp_next_scheduled('bg_identity_purge')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'bg_identity_purge');
        }
        add_action('bg_identity_purge', function () use ($container) {
            /** @var TokenRepository $tokens */
            $tokens = $container->get('identity.tokens');
            $purged = $tokens->purgeExpiredMagic();
            if ($purged > 0) {
                /** @var Logger $logger */
                $logger = $container->get('logger');
                $logger->info('identity', "Purged {$purged} expired magic tokens.");
            }
        });

        // Bulk provisioning: walk the whole constituent list in pages,
        // enqueue a per-constituent provision for each, self-continue.
        $container->get('jobs')->register('tier1_provision_all', function (array $args) use ($container) {
            $offset = (int) ($args['offset'] ?? 0);
            /** @var LglClient $lgl */
            $lgl = $container->get('lgl');
            /** @var Logger $logger */
            $logger = $container->get('logger');
            $batch = $lgl->listConstituents(100, $offset);
            if (!$batch) {
                if (!$lgl->lastRequestSucceeded()) {
                    $logger->error('identity', 'Bulk token provisioning stopped because LGL could not provide the next page.', [
                        'offset' => $offset,
                    ]);
                    return;
                }
                $logger->info('identity', 'Bulk token provisioning finished.', ['processed' => $offset]);
                return;
            }
            foreach ($batch as $i => $c) {
                if (!empty($c['id'])) {
                    if (!$container->get('jobs')->enqueue('tier1_provision', ['constituent_id' => (int) $c['id']], $i)) {
                        $logger->error('identity', 'Bulk token provisioning stopped because a job could not be scheduled.', [
                            'constituent' => (int) $c['id'],
                            'offset' => $offset,
                        ]);
                        return;
                    }
                }
            }
            $logger->info('identity', 'Bulk token provisioning: page queued.', ['offset' => $offset, 'count' => count($batch)]);
            if (!$container->get('jobs')->enqueue('tier1_provision_all', ['offset' => $offset + 100], 120)) {
                $logger->error('identity', 'Bulk token provisioning could not schedule its next page.', [
                    'offset' => $offset + 100,
                ]);
            }
        });

        // Tier 1 provisioning job: ensure a token exists for a constituent
        // and write it to the configured LGL custom attribute so LGL email
        // merges can inject it into Register links.
        $container->get('jobs')->register('tier1_provision', function (array $args) use ($container) {
            $cid = (int) ($args['constituent_id'] ?? 0);
            if (!$cid) return;
            /** @var TokenRepository $tokens */
            $tokens = $container->get('identity.tokens');
            /** @var LglClient $lgl */
            $lgl = $container->get('lgl');
            /** @var Logger $logger */
            $logger = $container->get('logger');

            $attrId = (int) $container->get('settings')->get('tier1_lgl_attribute_id', 0);
            if (!$attrId || !$lgl->hasKey()) {
                $logger->warn('identity', 'Newsletter link token setup was skipped because its LGL field or connection is unavailable.', ['constituent' => $cid]);
                return;
            }

            $attempt = max(1, (int) ($args['attempt'] ?? 1));
            $retry = static function () use ($container, $cid, $attempt): void {
                if ($attempt < 5) {
                    $container->get('jobs')->enqueue('tier1_provision', [
                        'constituent_id' => $cid,
                        'attempt' => $attempt + 1,
                    ], $attempt * 300);
                }
            };

            $made = $tokens->ensureTier1($cid);
            $raw = (string) ($made['raw'] ?? '');
            if (!$made['created']) {
                if (empty($made['row'])) {
                    $logger->error('identity', 'Newsletter link token could not be stored.', ['constituent' => $cid]);
                    $retry();
                    return;
                }
                $remote = $lgl->getConstituent($cid);
                if (!is_array($remote)) {
                    $logger->error('identity', 'Existing newsletter link token could not be checked because the LGL record was unavailable.', ['constituent' => $cid]);
                    $retry();
                    return;
                }
                foreach ((array) ($remote['custom_attrs'] ?? []) as $attr) {
                    if (is_array($attr)
                        && $tokens->matchesTier1($cid, trim((string) ($attr['value'] ?? $attr['description'] ?? '')))
                    ) return; // The active token is already present in LGL.
                }
                $raw = $tokens->rotateTier1($cid);
                if ($raw === '') {
                    $logger->error('identity', 'An orphaned newsletter link token could not be replaced safely.', ['constituent' => $cid]);
                    $retry();
                    return;
                }
            }

            $ok = $lgl->setCustomAttribute($cid, $attrId, $raw);
            $logger->info('identity', $ok
                ? "Tier 1 token provisioned to LGL for constituent {$cid}."
                : "Tier 1 token created but LGL write failed for constituent {$cid}.");
            if (!$ok) {
                // Do not strand an unrecoverable raw token. Revoke it before
                // retrying so the next attempt can create and deliver a new one.
                $tokens->revokeTier1($cid);
                $retry();
            }
        });

        $tier1Attr = (int) $container->get('settings')->get('tier1_lgl_attribute_id', 0);
        if ($tier1Attr > 0 && $container->get('lgl')->hasKey()
            && (string) get_option('bg_tier1_bulk_contract', '') !== '3.4.3'
            && $container->get('jobs')->enqueue('tier1_provision_all', ['offset' => 0], 60)
        ) update_option('bg_tier1_bulk_contract', '3.4.3', false);
    }

    private function renderVerificationResult(string $state): void
    {
        $success = $state === 'confirmed';
        $rawContinue = isset($_GET['bg_continue'])
            ? (string) wp_unslash($_GET['bg_continue'])
            : '';
        $continue = $rawContinue !== ''
            ? wp_validate_redirect($rawContinue, home_url('/'))
            : home_url('/');
        $destination = $success
            ? add_query_arg('bg_verified', '1', $continue)
            : home_url('/');

        $path = (string) wp_parse_url($continue, PHP_URL_PATH);
        $button = $success && strpos($path, '/bg-register/') !== false
            ? bg_str('magic_confirm_registration_button', 'Continue to registration')
            : ($success
                ? bg_str('magic_confirm_continue_button', 'Continue')
                : bg_str('magic_expired_button', 'Return to the site'));

        $title = $success
            ? bg_str('magic_confirm_title', 'Congratulations, you’re signed in')
            : ($state === 'failed'
                ? bg_str('identity_failed_title', 'We could not finish signing you in')
                : bg_str('magic_expired_title', 'This sign-in link has expired'));
        $body = $success
            ? bg_str(
                'magic_confirm_body',
                'Your email is confirmed on this device. Your saved contact and demographic information will prefill where it is requested, and you can review it before submitting.'
            )
            : ($state === 'failed'
                ? bg_str(
                    'identity_failed_body',
                    'Your email was confirmed, but this device could not retain the secure session. Please return to the form and request a new link; if the problem continues, contact the organizer.'
                )
                : bg_str_format(
                    'magic_expired_body',
                    'For your protection, each sign-in link works once and expires after {minutes} minutes. Return to the form and request a new link.',
                    ['minutes' => MagicLink::lifetimeMinutes()],
                    ['minutes']
                ));

        $documentTitle = $success
            ? bg_str('identity_confirmed_document_title', 'Signed in')
            : ($state === 'failed'
                ? bg_str('identity_failed_document_title', 'Sign-in could not be completed')
                : bg_str('identity_expired_document_title', 'Sign-in link expired'));
        add_filter('pre_get_document_title', static fn(): string => $documentTitle, PHP_INT_MAX);

        status_header($success ? 200 : ($state === 'failed' ? 500 : 410));
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        ob_start();
        ?>
        <style>
          .bg-identity-result{max-width:660px;margin:clamp(36px,8vw,88px) auto;padding:0 18px;
            color:var(--bgc-text,#1F2A24);font-family:var(--bgc-head,inherit)}
          .bg-identity-card{position:relative;overflow:hidden;background:var(--bgc-white,#fff);
            border:1px solid #D9E5D9;border-radius:18px;padding:clamp(28px,6vw,48px);
            text-align:center;box-shadow:0 12px 34px rgba(31,42,36,.10)}
          .bg-identity-card::before{content:"";position:absolute;inset:0 0 auto;height:6px;
            background:linear-gradient(90deg,var(--bgc-primary,#1F5130),var(--bgc-success,#2E7D4F),var(--bgc-accent,#C98A1B))}
          .bg-identity-mark{display:grid;place-items:center;width:68px;height:68px;margin:0 auto 20px;
            border-radius:50%;background:#EAF6EC;color:var(--bgc-primary,#1F5130);
            font-size:38px;font-weight:800;line-height:1}
          .bg-identity-card.is-expired .bg-identity-mark{background:#FFF4D6;color:#735600;font-size:31px}
          .bg-identity-card h1{margin:0 0 14px;color:var(--bgc-primary,#1F5130);
            font-size:clamp(1.65rem,4vw,2.25rem);line-height:1.15}
          .bg-identity-card p{max-width:520px;margin:0 auto 25px;font-size:1.05rem;line-height:1.65}
          .bg-identity-button{display:inline-block;padding:13px 26px;border-radius:999px;
            background:var(--bgc-primary,#1F5130);color:#fff!important;text-decoration:none!important;
            font-weight:750;box-shadow:0 4px 12px rgba(31,81,48,.23)}
          .bg-identity-button:hover,.bg-identity-button:focus{filter:brightness(1.1);transform:translateY(-1px)}
          .bg-identity-button:focus-visible{outline:3px solid #173D25;outline-offset:3px}
        </style>
        <div class="bg-identity-result" role="region" aria-labelledby="bg-identity-title">
          <section class="bg-identity-card<?php echo $success ? '' : ' is-expired'; ?>" aria-labelledby="bg-identity-title">
            <div class="bg-identity-mark" aria-hidden="true"><?php echo $success ? '&#10003;' : '&#8987;'; ?></div>
            <h1 id="bg-identity-title"><?php echo esc_html($title); ?></h1>
            <p><?php echo esc_html($body); ?></p>
            <a class="bg-identity-button" href="<?php echo esc_url($destination); ?>"><?php echo esc_html($button); ?></a>
          </section>
        </div>
        <?php
        $content = (string) ob_get_clean();
        $this->outputOwnedDocument($content, [
            'context' => 'identity',
            'body_class' => 'bg-identity-page bg-identity-result-page',
            'main_id' => 'bg-identity-main',
            'skip_label' => bg_str('identity_result_skip', 'Skip to sign-in result'),
        ]);
        exit;
    }

    private const PROMPT_COOKIE = 'bg_verify_prompt';

    private static function verifyPromptProof(Container $container, string $token): string
    {
        $key = $container->get('encryption')->signingKey('verify-prompt');
        return $key === '' ? '' : hash_hmac('sha256', $token, $key);
    }

    private function renderVerificationPrompt(string $token, string $to, string $proof = ''): void
    {
        $continue = $to !== '' ? wp_validate_redirect($to, home_url('/')) : home_url('/');
        if ($proof !== '') {
            setcookie(self::PROMPT_COOKIE, $proof, [
                'expires' => time() + 30 * MINUTE_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(),
                'httponly' => true, 'samesite' => 'Strict',
            ]);
        }
        $documentTitle = bg_str('identity_prompt_document_title', 'Confirm sign-in');
        add_filter('pre_get_document_title', static fn(): string => $documentTitle, PHP_INT_MAX);
        status_header(200);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        ob_start();
        ?>
        <div class="bg-identity-result" role="region" aria-labelledby="bg-identity-prompt-title" style="max-width:660px;margin:clamp(36px,8vw,88px) auto;padding:0 18px">
          <section class="bg-identity-card" style="background:#fff;border:1px solid #D9E5D9;border-radius:18px;padding:clamp(28px,6vw,48px);text-align:center;box-shadow:0 12px 34px rgba(31,42,36,.10)">
            <h1 id="bg-identity-prompt-title"><?php echo esc_html(bg_str('identity_prompt_title', 'Confirm this sign-in')); ?></h1>
            <p><?php echo esc_html(bg_str('identity_prompt_body', 'Select the button below to confirm your email and sign in on this device. The link is not used until you confirm.')); ?></p>
            <form method="post" action="<?php echo esc_url(home_url('/')); ?>">
              <input type="hidden" name="bg_verify_confirm" value="<?php echo esc_attr($token); ?>">
              <input type="hidden" name="bg_to" value="<?php echo esc_attr($continue); ?>">
              <button type="submit" style="border:0;border-radius:999px;padding:13px 26px;background:#1F5130;color:#fff;font-weight:750;cursor:pointer"><?php echo esc_html(bg_str('identity_prompt_button', 'Confirm and sign in')); ?></button>
            </form>
          </section>
        </div>
        <?php
        $content = (string) ob_get_clean();
        $this->outputOwnedDocument($content, [
            'context' => 'identity',
            'body_class' => 'bg-identity-page bg-identity-prompt-page',
            'main_id' => 'bg-identity-main',
            'skip_label' => bg_str('identity_prompt_skip', 'Skip to sign-in confirmation'),
        ], home_url('/'));
        exit;
    }

    /** Use BosqueGrande's public frame when available, retaining theme fallback. */
    private function outputOwnedDocument(string $content, array $args, string $cleanUrl = ''): void
    {
        $urlCleaner = null;
        if ($cleanUrl !== '') {
            $encodedUrl = (string) wp_json_encode($cleanUrl);
            $urlCleaner = static function () use ($encodedUrl): void {
                echo '<script id="bg-sensitive-url-cleaner">try{if(window.history&&window.history.replaceState){window.history.replaceState(null,"",' . $encodedUrl . ');}}catch(e){}</script>';
            };
            // Remove the bearer token before any later wp_head integration can
            // inspect location.href. The server-rendered hidden field retains
            // the token needed for the deliberate confirmation POST.
            add_action('wp_head', $urlCleaner, -PHP_INT_MAX);
        }
        try {
            if (function_exists('bgev_output_owned_document')) {
                \bgev_output_owned_document($content, $args);
                return;
            }
            get_header();
            echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer-owned markup.
            get_footer();
        } finally {
            if ($urlCleaner !== null) {
                remove_action('wp_head', $urlCleaner, -PHP_INT_MAX);
            }
        }
    }

    /** @param array<string, array<string, mixed>> $defaults */
    public static function wordingDefaults(array $defaults): array
    {
        $items = [
            'identity_confirmed_document_title' => ['Successful sign-in screen: browser title', 'Signed in'],
            'identity_expired_document_title' => ['Expired sign-in screen: browser title', 'Sign-in link expired'],
            'identity_failed_document_title' => ['Sign-in failure screen: browser title', 'Sign-in could not be completed'],
            'identity_failed_title' => ['Sign-in failure screen: heading', 'We could not finish signing you in'],
            'identity_failed_body' => ['Sign-in failure screen: explanation', 'Your email was confirmed, but this device could not retain the secure session. Please return to the form and request a new link; if the problem continues, contact the organizer.'],
            'identity_result_skip' => ['Sign-in result screen: skip link', 'Skip to sign-in result'],
            'identity_prompt_document_title' => ['Sign-in confirmation screen: browser title', 'Confirm sign-in'],
            'identity_prompt_title' => ['Sign-in confirmation screen: heading', 'Confirm this sign-in'],
            'identity_prompt_body' => ['Sign-in confirmation screen: explanation', 'Select the button below to confirm your email and sign in on this device. The link is not used until you confirm.'],
            'identity_prompt_button' => ['Sign-in confirmation screen: button', 'Confirm and sign in'],
            'identity_prompt_skip' => ['Sign-in confirmation screen: skip link', 'Skip to sign-in confirmation'],
        ];
        foreach ($items as $key => [$label, $text]) {
            $defaults[$key] = [
                'label' => $label,
                'text' => $text,
                'group' => 'Identity and sign-in',
            ];
        }
        return $defaults;
    }

    public function deactivate(Container $container): void
    {
        wp_clear_scheduled_hook('bg_identity_purge');
    }
}
