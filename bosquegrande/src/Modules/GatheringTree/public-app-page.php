<?php
/**
 * Theme-independent document for the primary Calendar and My Profile pages.
 *
 * The active page's normal content pipeline runs exactly once so WordPress
 * shortcodes and approved integrations keep working, while the theme does not
 * add its own title, masthead, sidebar, or footer around the BosqueGrande app.
 */

defined('ABSPATH') || exit;

$page = get_queried_object();
if (!$page instanceof WP_Post || $page->post_type !== 'page') return;

$raw = (string) $page->post_content;
$context = bgev_public_app_page_context($page);
if ($context === '') return;
$args = $context === 'profile'
    ? [
        'context' => 'profile',
        'main_id' => 'bg-profile-app-main',
        'title' => bg_str('shell_profile_title', 'My Profile'),
        'eyebrow' => bg_str('shell_profile_eyebrow', 'Your account'),
        'intro' => bg_str('shell_profile_intro', 'Review your information, newsletter subscriptions, privacy preference, and monthly-donation status in one secure place.'),
        'skip_label' => bg_str('profile_shell_skip_label', 'Skip to My Profile'),
    ]
    : [
        'context' => 'calendar',
        'main_id' => 'bg-calendar-app-main',
        'title' => bg_str('shell_calendar_title', 'Community Events'),
        'eyebrow' => bg_str('shell_calendar_eyebrow', '{org}'),
        'intro' => bg_str('shell_calendar_intro', 'Search by place or interest, move between Pinboard, Calendar, and List, register, or suggest a community event.'),
        'skip_label' => bgev_wording('calendar_skip', 'Skip to the events calendar'),
        'right_url' => (string) get_permalink($page),
    ];

bgev_public_document_context($context);
try {
    $content = post_password_required($page)
        ? (string) get_the_password_form($page)
        : (string) apply_filters('the_content', $raw);
} finally {
    bgev_public_document_context('');
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
    <meta name="bosquegrande-shell-template" content="public-app-<?php echo esc_attr($context); ?>">
    <?php wp_head(); ?>
</head>
<body <?php body_class('bg-event-app-page bg-public-app-page bg-public-app-page--' . $context); ?>>
<?php wp_body_open(); ?>
<?php echo bgev_public_app_shell($content, $args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the source renderers. ?>
<?php wp_footer(); ?>
</body>
</html>
