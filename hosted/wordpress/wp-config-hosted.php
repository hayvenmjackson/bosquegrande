<?php
/**
 * Hosted-edition settings, loaded from wp-config.php through
 * WORDPRESS_CONFIG_EXTRA before WordPress starts.
 */

// Only the Caddy proxy can reach this container, so its scheme header is trusted.
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// The network is turned on once bin/install.sh has created it.
define('WP_ALLOW_MULTISITE', true);
if (is_file('/var/lib/bosquegrande/network-installed')) {
    define('MULTISITE', true);
    define('SUBDOMAIN_INSTALL', getenv('BG_SUBDOMAINS') === '1');
    define('DOMAIN_CURRENT_SITE', (string) getenv('BG_DOMAIN'));
    define('PATH_CURRENT_SITE', '/');
    define('SITE_ID_CURRENT_SITE', 1);
    define('BLOG_ID_CURRENT_SITE', 1);
}

// Software changes arrive only by rebuilding the image.
define('DISALLOW_FILE_EDIT', true);
define('DISALLOW_FILE_MODS', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);

define('FORCE_SSL_ADMIN', true);
define('WP_ENVIRONMENT_TYPE', 'production');
// WP_DEBUG is already defined (off) by the image's wp-config.php.
define('WP_DEBUG_DISPLAY', false);
define('WP_POST_REVISIONS', 20);

// The cron container runs due events every minute for every site.
define('DISABLE_WP_CRON', true);

// Private import files live outside the web root (see the mu-plugin).
define('BG_PRIVATE_DIR', '/var/lib/bosquegrande/private');
