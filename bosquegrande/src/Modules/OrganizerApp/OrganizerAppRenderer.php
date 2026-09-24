<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\OrganizerApp;

use BosqueGrande\Modules\OrganizerPortal\OrganizerAccess;

if (!defined('ABSPATH')) exit;

/** Direct, theme-independent PWA shell and its manifest/service worker. */
final class OrganizerAppRenderer
{
    public const APP_PATH = '/bg-organizer-app/';
    public const MANIFEST_PATH = '/bg-organizer-app-manifest.webmanifest';
    public const SW_PATH = '/bg-organizer-app-sw.js';
    public const OFFLINE_PATH = '/bg-organizer-app-offline/';

    public function __construct(private OrganizerAccess $access)
    {
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'route'], 0);
        add_filter('login_redirect', function (string $redirect, string $requested, $user): string {
            if ($user instanceof \WP_User
                && in_array(OrganizerAccess::ROLE, (array) $user->roles, true)
                && !user_can($user, 'manage_options')
            ) return self::url();
            return $redirect;
        }, 25, 3);
        add_filter('bg_organizer_home_url', static fn(string $url): string => self::url());
        add_action('wp_head', [$this, 'homepageLauncherStyles'], 30);
        add_action('wp_footer', [$this, 'homepageLauncher'], 5);
    }

    /**
     * Home-page shortcut to the organizer app, shown to signed-in organizers.
     * Sites that want it visible to everyone can return true from the
     * bg_organizer_launcher_public filter; the app still requires sign-in.
     * bg_organizer_launcher_show has the final say on any public page, and
     * is told whether the visitor is a signed-in organizer.
     */
    private function showLauncher(): bool
    {
        if (is_admin()) return false;
        $isOrganizer = is_user_logged_in() && $this->access->canUsePortal(get_current_user_id());
        $default = is_front_page()
            && ((bool) apply_filters('bg_organizer_launcher_public', false) || $isOrganizer);
        return (bool) apply_filters('bg_organizer_launcher_show', $default, $isOrganizer);
    }

    public function homepageLauncherStyles(): void
    {
        if (!$this->showLauncher()) return;
        echo '<style id="bg-organizer-home-launcher-style">'
            . '.bg-organizer-home-launcher{position:fixed;z-index:99990;right:16px;bottom:16px;display:inline-flex;align-items:center;justify-content:center;min-height:44px;max-width:calc(100vw - 32px);padding:10px 20px;border:0;border-radius:999px;background:var(--bgc-primary,#1F5130);box-shadow:0 7px 20px rgba(0,0,0,.24);color:#fff!important;font:700 15px/1.15 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;text-align:center;text-decoration:none!important}'
            . '.bg-organizer-home-launcher:hover,.bg-organizer-home-launcher:focus-visible{background:var(--bgc-accent,#C98A1B);color:#111!important;outline:3px solid rgba(255,255,255,.8);outline-offset:3px}'
            . '@media(max-width:782px){.bg-organizer-home-launcher{font-size:14px;padding:9px 16px}}'
            . '@media(prefers-reduced-motion:no-preference){.bg-organizer-home-launcher{transition:background .16s ease}}'
            . '</style>';
    }

    public function homepageLauncher(): void
    {
        if (!$this->showLauncher()) return;
        echo '<a class="bg-organizer-home-launcher" href="' . esc_url(self::url()) . '" aria-label="'
            . esc_attr(OrganizerAppWording::text('organizer_app_launcher_aria', 'Open the Organizer App')) . '">'
            . esc_html(OrganizerAppWording::text('organizer_app_launcher_label', 'Event Organizer App')) . '</a>';
    }

    public static function url(): string
    {
        return home_url(self::APP_PATH);
    }

    public function route(): void
    {
        if (is_admin()) return;
        $path = $this->relativePath();
        if ($path === self::MANIFEST_PATH) {
            $this->manifest();
            exit;
        }
        if ($path === self::SW_PATH) {
            $this->serviceWorker();
            exit;
        }
        if (rtrim($path, '/') === rtrim(self::OFFLINE_PATH, '/')) {
            $this->offline();
            exit;
        }
        if (rtrim($path, '/') !== rtrim(self::APP_PATH, '/')) return;

        if (!is_user_logged_in()) {
            $authenticated = (bool) apply_filters('bg_organizer_try_constituent_auth', false);
            if (!$authenticated || !is_user_logged_in()) {
                $this->organizerGate();
                exit;
            }
        }
        $userId = get_current_user_id();
        if (!$this->access->canUsePortal($userId)) {
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            status_header(403);
            nocache_headers();
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
            header('X-Robots-Tag: noindex, nofollow', true);
            header('X-Frame-Options: SAMEORIGIN', true);
            header('X-Content-Type-Options: nosniff', true);
            header('Referrer-Policy: no-referrer', true);
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()', true);
            header("Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'", true);
            $title = OrganizerAppWording::text('organizer_app_access_title', 'Organizer access required');
            $heading = OrganizerAppWording::text('organizer_app_access_heading', 'Organizer access required');
            $body = OrganizerAppWording::text('organizer_app_access_body', 'This account is not authorized to use Event Organizer App.');
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>' . esc_html($title) . '</title><body><main><h1>' . esc_html($heading) . '</h1>'
                . '<p>' . esc_html($body) . '</p></main></body></html>';
            exit;
        }
        $this->shell();
        exit;
    }

    private function organizerGate(): void
    {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        status_header(200);
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
        header('X-Robots-Tag: noindex, nofollow', true);
        header('X-Frame-Options: SAMEORIGIN', true);
        header('Referrer-Policy: same-origin', true);
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()', true);
        $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        header("Content-Security-Policy: default-src 'self'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'", true);
        $endpoint = rest_url('bosquegrande/v1/identity/request-link');
        $appUrl = self::url();
        $gateCopy = [
            'sending' => OrganizerAppWording::text('organizer_app_gate_sending', 'Sending…'),
            'submit' => OrganizerAppWording::text('organizer_app_gate_submit', 'Email my secure sign-in link'),
            'success' => OrganizerAppWording::text('organizer_app_gate_success', 'If these details match an assigned constituent, a secure sign-in link is on its way.'),
            'error' => OrganizerAppWording::text('organizer_app_gate_error', 'The request could not be sent. Check the connection and try again.'),
        ];
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<meta name="theme-color" content="#21352b"><title>' . esc_html(OrganizerAppWording::text('organizer_app_gate_title', 'Organizer sign in')) . '</title>'
            . '<style nonce="' . esc_attr($nonce) . '">:root{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#21352b;background:#f4f2ea}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px}main{width:min(100%,520px);background:#fff;border:1px solid #d7d1c4;border-radius:18px;padding:28px;box-shadow:0 12px 35px rgba(33,53,43,.12)}.eyebrow{text-transform:uppercase;letter-spacing:.12em;font-size:.75rem;font-weight:750;margin:0;color:#6f3aa8}h1{margin:.35rem 0 .75rem;line-height:1.08}p{line-height:1.5}.grid{display:grid;gap:14px;margin:22px 0}label{display:grid;gap:6px;font-weight:650}input{font:inherit;padding:12px;border:1px solid #9b9589;border-radius:9px;width:100%}button{font:inherit;border:0;border-radius:999px;padding:12px 20px;background:#6f3aa8;color:#fff;font-weight:750;cursor:pointer;width:100%}button:disabled{opacity:.62;cursor:wait}.note{font-size:.9rem;color:#5c5a54}.status{padding:12px;border-radius:9px;background:#eef3ef;margin-top:14px}.reload{display:inline-block;margin-top:12px;color:#5c2d78;font-weight:700}</style></head><body><main>'
            . '<p class="eyebrow">' . esc_html(OrganizerAppWording::text('organizer_app_brand_eyebrow', 'BosqueGrande')) . '</p><h1>' . esc_html(OrganizerAppWording::text('organizer_app_gate_heading', 'Organizer sign in')) . '</h1><p>' . esc_html(OrganizerAppWording::text('organizer_app_gate_intro', 'Use the same verified identity used for My Profile. On a device that is already verified, reloading this page normally opens the app in one step.')) . '</p>'
            . '<form id="bg-organizer-gate"><div class="grid"><label>' . esc_html(OrganizerAppWording::text('organizer_app_field_first_name', 'First name')) . '<input name="first_name" autocomplete="given-name" required></label><label>' . esc_html(OrganizerAppWording::text('organizer_app_field_last_name', 'Last name')) . '<input name="last_name" autocomplete="family-name" required></label><label>' . esc_html(OrganizerAppWording::text('organizer_app_field_email', 'Email')) . '<input name="email" type="email" autocomplete="email" required></label></div><button type="submit">' . esc_html($gateCopy['submit']) . '</button></form>'
            . '<div id="bg-organizer-gate-status" class="status" hidden role="status" aria-live="polite"></div>'
            . '<p class="note">' . esc_html(OrganizerAppWording::format('organizer_app_gate_note', 'Only constituents with an active organizer or host assignment can enter. The sign-in link expires after {minutes} minutes and returns to this app.', ['minutes' => \BosqueGrande\Modules\Identity\MagicLink::lifetimeMinutes()])) . '</p>'
            . '<a class="reload" href="' . esc_url($appUrl) . '">' . esc_html(OrganizerAppWording::text('organizer_app_gate_reload', 'Reload and check this device')) . '</a>'
            . '<script nonce="' . esc_attr($nonce) . '">(function(){const c=' . wp_json_encode($gateCopy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ',f=document.getElementById("bg-organizer-gate"),s=document.getElementById("bg-organizer-gate-status"),b=f.querySelector("button");f.addEventListener("submit",async function(e){e.preventDefault();if(!f.reportValidity())return;b.disabled=true;b.textContent=c.sending;s.hidden=true;const d=Object.fromEntries(new FormData(f).entries());d.redirect=' . wp_json_encode($appUrl) . ';try{const r=await fetch(' . wp_json_encode($endpoint) . ',{method:"POST",headers:{"Content-Type":"application/json"},credentials:"same-origin",body:JSON.stringify(d)});const j=await r.json();s.textContent=(j&&j.message)||c.success;s.hidden=false;f.reset()}catch(x){s.textContent=c.error;s.hidden=false}finally{b.disabled=false;b.textContent=c.submit}})})();</script>'
            . '</main></body></html>';
    }

    private function shell(): void
    {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        status_header(200);
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
        header('X-Robots-Tag: noindex, nofollow', true);
        header('X-Frame-Options: SAMEORIGIN', true);
        header('Referrer-Policy: same-origin', true);
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()', true);
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; manifest-src 'self'; worker-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'", true);
        $assetRevision = self::assetRevision();
        $css = BG_URL . 'src/Modules/OrganizerApp/assets/organizer-app.css?ver=' . rawurlencode($assetRevision);
        $js = BG_URL . 'src/Modules/OrganizerApp/assets/organizer-app.js?ver=' . rawurlencode($assetRevision);
        $icon = BG_URL . 'src/Modules/OrganizerApp/assets/icon-192.png?ver=' . rawurlencode($assetRevision);
        $user = wp_get_current_user();
        $clientWording = wp_json_encode(OrganizerAppWording::client(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($clientWording)) $clientWording = '{}';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<meta name="theme-color" content="#21352b">'
            . '<meta name="apple-mobile-web-app-capable" content="yes">'
            . '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">'
            . '<meta name="apple-mobile-web-app-title" content="' . esc_attr(OrganizerAppWording::text('organizer_app_short_title', 'Organizer App')) . '">'
            . '<title>' . esc_html(OrganizerAppWording::text('organizer_app_document_title', 'Event Organizer App')) . '</title>'
            . '<link rel="manifest" href="' . esc_url(home_url(self::MANIFEST_PATH)) . '">'
            . '<link rel="icon" sizes="192x192" href="' . esc_url($icon) . '">'
            . '<link rel="apple-touch-icon" href="' . esc_url($icon) . '">'
            . '<link rel="stylesheet" href="' . esc_url($css) . '">'
            . '</head><body><a class="bg-app-skip" href="#bg-app-main">' . esc_html(OrganizerAppWording::text('organizer_app_skip', 'Skip to app content')) . '</a>'
            . '<div id="bg-organizer-app"'
            . ' data-rest-root="' . esc_attr(trailingslashit(rest_url('bosquegrande/v1/organizer-app'))) . '"'
            . ' data-rest-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '"'
            . ' data-app-url="' . esc_attr(self::url()) . '"'
            . ' data-sw-url="' . esc_attr(home_url(self::SW_PATH)) . '"'
            . ' data-version="' . esc_attr(BG_VERSION) . '"'
            . ' data-currency="' . esc_attr(\BosqueGrande\Core\Money::code()) . '"'
            . ' data-currency-symbol="' . esc_attr(\BosqueGrande\Core\Money::symbol()) . '"'
            . ' data-user-name="' . esc_attr((string) $user->display_name) . '"'
            . ' data-logout-url="' . esc_attr(wp_logout_url(self::url())) . '"'
            . ' data-wording="' . esc_attr($clientWording) . '">'
            . '<header class="bg-app-header"><div><p class="bg-app-eyebrow">' . esc_html(OrganizerAppWording::text('organizer_app_brand_eyebrow', 'BosqueGrande')) . '</p><h1>' . esc_html(OrganizerAppWording::text('organizer_app_heading', 'Event Organizers')) . '</h1></div>'
            . '<div class="bg-app-header-actions"><span id="bg-online-state" class="bg-status-pill" aria-live="polite">' . esc_html(OrganizerAppWording::text('organizer_app_connection_checking', 'Checking connection')) . '</span>'
            . '<button id="bg-install-button" class="bg-button bg-button-secondary" type="button" hidden>' . esc_html(OrganizerAppWording::text('organizer_app_install', 'Install app')) . '</button>'
            . '<button id="bg-refresh-button" class="bg-icon-button" type="button" aria-label="' . esc_attr(OrganizerAppWording::text('organizer_app_refresh_aria', 'Refresh app data')) . '">↻</button></div></header>'
            . '<main id="bg-app-main" tabindex="-1"><div class="bg-loading" role="status"><span class="bg-spinner" aria-hidden="true"></span>' . esc_html(OrganizerAppWording::text('organizer_app_loading_assigned', 'Loading assigned events…')) . '</div></main>'
            . '<nav id="bg-app-nav" class="bg-bottom-nav" aria-label="' . esc_attr(OrganizerAppWording::text('organizer_app_navigation_aria', 'Application navigation')) . '" hidden></nav>'
            . '<div id="bg-app-toast" class="bg-toast" role="status" aria-live="polite" hidden></div>'
            . '</div><noscript><main class="bg-no-script"><h1>' . esc_html(OrganizerAppWording::text('organizer_app_noscript_heading', 'JavaScript is required')) . '</h1><p>' . esc_html(OrganizerAppWording::text('organizer_app_noscript_body', 'The organizer app needs JavaScript for secure check-in and payment workflows. The original Organizer Portal remains available as a fallback.')) . '</p></main></noscript>'
            . '<script type="module" src="' . esc_url($js) . '"></script></body></html>';
    }

    private function manifest(): void
    {
        status_header(200);
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        $base = BG_URL . 'src/Modules/OrganizerApp/assets/';
        $iconVersion = '?ver=' . rawurlencode(self::assetRevision());
        echo wp_json_encode([
            'id' => self::url(),
            'name' => OrganizerAppWording::text('organizer_app_document_title', 'Event Organizer App'),
            'short_name' => OrganizerAppWording::text('organizer_app_short_title', 'Organizer App'),
            'description' => OrganizerAppWording::text('organizer_app_manifest_description', 'Mobile event check-in, walk-ins, cash and check records, private attendee intake, and event closeout.'),
            'start_url' => self::url(),
            'scope' => self::url(),
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#f4f2ea',
            'theme_color' => '#21352b',
            'icons' => [
                ['src' => $base . 'icon-192.png' . $iconVersion, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $base . 'icon-512.png' . $iconVersion, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    private function serviceWorker(): void
    {
        status_header(200);
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $allowedScope = (string) wp_parse_url(self::url(), PHP_URL_PATH);
        header('Service-Worker-Allowed: ' . ($allowedScope !== '' ? trailingslashit($allowedScope) : '/'));
        header('X-Content-Type-Options: nosniff');
        $assets = [
            BG_URL . 'src/Modules/OrganizerApp/assets/organizer-app.css?ver=' . rawurlencode(self::assetRevision()),
            BG_URL . 'src/Modules/OrganizerApp/assets/organizer-app.js?ver=' . rawurlencode(self::assetRevision()),
            BG_URL . 'src/Modules/OrganizerApp/assets/icon-192.png?ver=' . rawurlencode(self::assetRevision()),
            BG_URL . 'src/Modules/OrganizerApp/assets/icon-512.png?ver=' . rawurlencode(self::assetRevision()),
            home_url(self::OFFLINE_PATH),
        ];
        $script = <<<'JS'
const CACHE = __CACHE__;
const APP_SCOPE = __SCOPE__;
const REST_MARKER = __REST_MARKER__;
const PRECACHE = __ASSETS__;
self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('bg-organizer-app-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  const decodedHref = decodeURIComponent(url.href);
  if (request.method !== 'GET' || decodedHref.includes(REST_MARKER)) return;
  if (request.mode === 'navigate' && url.pathname.startsWith(APP_SCOPE)) {
    event.respondWith(fetch(request, {cache: 'no-store'}).catch(() => caches.match(__OFFLINE__)));
    return;
  }
  if (PRECACHE.includes(url.href)) {
    event.respondWith(caches.match(request).then(hit => hit || fetch(request)));
  }
});
JS;
        echo str_replace(
            ['__CACHE__', '__SCOPE__', '__REST_MARKER__', '__ASSETS__', '__OFFLINE__'],
            [
                wp_json_encode('bg-organizer-app-' . BG_VERSION . '-' . self::assetRevision()),
                wp_json_encode((string) wp_parse_url(self::url(), PHP_URL_PATH)),
                wp_json_encode('bosquegrande/v1/organizer-app'),
                wp_json_encode($assets, JSON_UNESCAPED_SLASHES),
                wp_json_encode(home_url(self::OFFLINE_PATH)),
            ],
            $script
        );
    }

    private function offline(): void
    {
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        $protectedData = 'rosters, attendee details, attendance writes, or payment records';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="theme-color" content="#21352b"><title>' . esc_html(OrganizerAppWording::text('organizer_app_offline_title', 'Organizer App (offline)')) . '</title>'
            . '<style>body{margin:0;background:#f4f2ea;color:#21352b;font:17px/1.5 system-ui,sans-serif;display:grid;min-height:100vh;place-items:center}main{max-width:34rem;padding:2rem}h1{line-height:1.1}a{color:#5c2d78;font-weight:700}</style></head>'
            . '<body><main><h1>' . esc_html(OrganizerAppWording::text('organizer_app_offline_heading', 'You are offline')) . '</h1><p>'
            . esc_html(OrganizerAppWording::format('organizer_app_offline_body', 'BosqueGrande does not cache {protected_data} on this device. Reconnect before continuing.', ['protected_data' => $protectedData])) . '</p>'
            . '<p><a href="' . esc_url(self::url()) . '">' . esc_html(OrganizerAppWording::text('organizer_app_offline_retry', 'Try the organizer app again')) . '</a></p></main></body></html>';
    }

    private function relativePath(): string
    {
        $requestPath = (string) wp_parse_url(
            isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/',
            PHP_URL_PATH
        );
        $homePath = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($homePath !== '' && !str_starts_with($requestPath, $homePath . '/')) return $requestPath;
        $relative = $homePath !== '' ? substr($requestPath, strlen($homePath)) : $requestPath;
        return '/' . ltrim((string) $relative, '/');
    }

    /** Internal content revision combined with the current BG_VERSION cache key. */
    private static function assetRevision(): string
    {
        static $revision = null;
        if (is_string($revision)) return $revision;
        $context = hash_init('sha256');
        $files = [
            'OrganizerAppRenderer.php' => __FILE__,
            'organizer-app.css' => __DIR__ . '/assets/organizer-app.css',
            'organizer-app.js' => __DIR__ . '/assets/organizer-app.js',
            'icon-192.png' => __DIR__ . '/assets/icon-192.png',
            'icon-512.png' => __DIR__ . '/assets/icon-512.png',
        ];
        foreach ($files as $file => $path) {
            hash_update($context, $file . "\0");
            if (is_readable($path)) hash_update_file($context, $path);
        }
        $revision = substr(hash_final($context), 0, 12);
        return $revision !== '' ? $revision : substr(hash('sha256', BG_VERSION), 0, 12);
    }
}
