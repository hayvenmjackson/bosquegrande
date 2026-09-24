<?php
/**
 * Plugin Name: BosqueGrande Hosted
 * Description: Locks down a multisite network that hosts BosqueGrande for several organizations.
 * Version: 5.0.0
 * Author: BosqueGrande contributors
 * License: MIT
 *
 * Each site is one organization. This file:
 *  - gives every site its own keyring file, sealed with a key derived from
 *    the operator's master key (never stored in WordPress or the database);
 *  - keeps each site's private import files in its own folder outside the web root;
 *  - frames public pages with BosqueGrande and turns off comments, feeds,
 *    author archives, the users sitemap, XML-RPC, and open REST routes;
 *  - removes plugin and theme management from site administrators;
 *  - sends mail through the operator's relay for sites without their own;
 *  - throttles failed logins and sends security headers.
 */

defined('ABSPATH') || exit;

const BGH_KEYRING_DIR = '/var/lib/bosquegrande/keyrings';
const BGH_PRIVATE_DIR = '/var/lib/bosquegrande/private';

/** The operator's 32-byte master key, from a Docker secret or environment variable. */
function bgh_master_key(): string
{
    static $key = null;
    if ($key !== null) return $key;
    $key = '';
    $file = (string) getenv('BG_MASTER_KEY_FILE');
    $raw = $file !== '' && is_readable($file) ? trim((string) file_get_contents($file)) : trim((string) getenv('BG_MASTER_KEY'));
    $decoded = base64_decode($raw, true);
    if (is_string($decoded) && strlen($decoded) === 32) $key = $decoded;
    return $key;
}

/* ── Per-organization keyrings and storage ─────────────────────────── */

add_filter('bg_keyring_path', static function (): string {
    return BGH_KEYRING_DIR . '/site-' . (int) get_current_blog_id() . '.php';
}, 1);

// Without a valid master key this returns a malformed value on purpose, so
// BosqueGrande refuses to create or read an unsealed keyring.
add_filter('bg_keyring_seal_key', static function (): string {
    $master = bgh_master_key();
    if ($master === '') return 'missing-master-key';
    return hash_hmac('sha256', 'bosquegrande-site-seal|' . (int) get_current_blog_id(), $master, true);
}, 1);

add_filter('bg_private_dir', static fn(): string => BGH_PRIVATE_DIR, 1);

/* ── Public surface ────────────────────────────────────────────────── */

// BosqueGrande frames every public page and post with its own header and footer.
add_filter('option_bosquegrande_settings', static function ($settings) {
    $settings = is_array($settings) ? $settings : [];
    $settings['public_presentation'] = 'site';
    return $settings;
});

add_filter('xmlrpc_enabled', '__return_false');
add_filter('xmlrpc_methods', '__return_empty_array');
add_filter('wp_is_application_passwords_available', '__return_false');
add_filter('comments_open', '__return_false', 20);
add_filter('pings_open', '__return_false', 20);
add_filter('feed_links_show_comments_feed', '__return_false');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'feed_links', 2);
remove_action('wp_head', 'feed_links_extra', 3);

add_action('template_redirect', static function (): void {
    if (is_feed() || is_author() || is_comment_feed() || (isset($_GET['author']) && !is_admin())) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
}, 0);

// Anonymous REST access is limited to BosqueGrande's public routes.
add_filter('rest_pre_dispatch', static function ($result, $server, WP_REST_Request $request) {
    if ($result !== null || is_user_logged_in()) return $result;
    $route = ltrim((string) $request->get_route(), '/');
    foreach (['bosquegrande/v1/', 'gathering-tree/v1/'] as $allowed) {
        if (str_starts_with($route, $allowed)) return $result;
    }
    return new WP_Error('rest_forbidden', 'Not available.', ['status' => 401]);
}, 10, 3);

add_filter('rest_endpoints', static function (array $endpoints): array {
    if (is_user_logged_in()) return $endpoints;
    unset($endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)']);
    return $endpoints;
});

// The sitemap would otherwise list every administrator's login name.
add_filter('wp_sitemaps_add_provider', static fn($provider, string $name) => $name === 'users' ? false : $provider, 10, 2);

/* ── Administration ────────────────────────────────────────────────── */

// Site administrators manage their organization, not the software.
add_action('admin_menu', static function (): void {
    if (is_super_admin()) return;
    foreach (['plugins.php', 'themes.php', 'tools.php', 'edit-comments.php', 'edit.php'] as $page) {
        remove_menu_page($page);
    }
}, 999);

add_action('admin_init', static function (): void {
    if (is_super_admin() || wp_doing_ajax()) return;
    global $pagenow;
    if (in_array($pagenow, ['plugins.php', 'plugin-install.php', 'themes.php', 'theme-install.php', 'tools.php', 'import.php', 'export.php'], true)) {
        wp_safe_redirect(admin_url('admin.php?page=bosquegrande'));
        exit;
    }
});

// Refuse to run without a master key rather than fall back to plaintext keyrings.
add_action('admin_notices', static function (): void {
    if (bgh_master_key() !== '' || !current_user_can('manage_options')) return;
    echo '<div class="notice notice-error"><p><strong>BosqueGrande is locked:</strong> '
        . 'the hosting master key is missing, so no organization data can be read or written. '
        . 'Contact the service operator.</p></div>';
});

/* ── Operator mail relay ────────────────────────────────────────────── */

// The image has no local mail server, so PHP's mail() would drop messages.
// Sites that have not entered their own relay in BosqueGrande, and the
// network itself (password resets, new-site notices), send through the
// operator's relay from .env instead. Runs last so an organization's own
// relay, set by BosqueGrande on this same hook, always wins.
add_action('phpmailer_init', static function ($phpmailer): void {
    if ($phpmailer->Mailer === 'smtp') return;
    $host = trim((string) getenv('BG_SMTP_HOST'));
    if ($host === '') return;
    $phpmailer->isSMTP();
    $phpmailer->Host = $host;
    $phpmailer->Port = (int) (getenv('BG_SMTP_PORT') ?: 587);
    $secure = (string) (getenv('BG_SMTP_SECURE') ?: 'tls');
    $phpmailer->SMTPSecure = in_array($secure, ['tls', 'ssl'], true) ? $secure : '';
    $user = trim((string) getenv('BG_SMTP_USER'));
    if ($user !== '') {
        $file = (string) getenv('BG_SMTP_PASSWORD_FILE');
        $phpmailer->SMTPAuth = true;
        $phpmailer->Username = $user;
        $phpmailer->Password = $file !== '' && is_readable($file) ? trim((string) file_get_contents($file)) : '';
    }
    // Send from the operator's address so the relay's SPF and DKIM line up;
    // replies still go to the organization.
    $from = trim((string) getenv('BG_MAIL_FROM'));
    if ($from !== '' && is_email($from) && strcasecmp((string) $phpmailer->From, $from) !== 0) {
        if (!$phpmailer->getReplyToAddresses() && is_email((string) $phpmailer->From)) {
            $phpmailer->addReplyTo((string) $phpmailer->From, (string) $phpmailer->FromName);
        }
        $phpmailer->setFrom($from, (string) $phpmailer->FromName, false);
    }
}, PHP_INT_MAX);

/* ── Login throttling ──────────────────────────────────────────────── */

function bgh_login_key(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return 'bgh_login_fail_' . md5($ip);
}

// Runs last, so it overrides WordPress's own password check: a locked-out
// address is refused even when the password is right.
add_filter('authenticate', static function ($user) {
    if ((int) get_site_transient(bgh_login_key()) >= 10) {
        return new WP_Error('too_many_attempts', 'Too many failed sign-in attempts. Try again in 15 minutes.');
    }
    return $user;
}, PHP_INT_MAX);

add_action('wp_login_failed', static function (): void {
    $key = bgh_login_key();
    set_site_transient($key, (int) get_site_transient($key) + 1, 15 * MINUTE_IN_SECONDS);
});

/* ── Headers ───────────────────────────────────────────────────────── */

add_action('send_headers', static function (): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(self "https://js.stripe.com" "https://*.js.stripe.com")');
    header('X-Frame-Options: SAMEORIGIN');
});
