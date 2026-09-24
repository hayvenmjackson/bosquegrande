<?php
/**
 * Theme-independent public document for a single BosqueGrande event.
 *
 * Deliberately does not call get_header() or get_footer(): those functions
 * render the active theme's visual shell. WordPress's public hooks remain in
 * place so SEO integrations, the admin bar, consent tools, and enqueued
 * BosqueGrande assets continue to work normally.
 */

defined('ABSPATH') || exit;

$event = get_queried_object();
if (!$event instanceof WP_Post || $event->post_type !== 'bg_event') {
    return;
}

$calendarUrl = bgev_calendar_return_url();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (!current_theme_supports('title-tag')) : ?>
        <title><?php echo esc_html(wp_get_document_title()); ?></title>
    <?php endif; ?>
    <meta name="bosquegrande-shell-template" content="single-event">
    <?php wp_head(); ?>
</head>
<body <?php body_class('bg-event-app-page'); ?>>
<?php wp_body_open(); ?>
<a class="bg-event-skip" href="#bg-event-main"><?php echo esc_html(bgev_wording('event_skip', 'Skip to event details')); ?></a>

<div class="bg-event-app-frame">
    <?php echo bgev_public_app_header($calendarUrl); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

    <main id="bg-event-main" class="bg-event-app-main" tabindex="-1">
        <?php echo bgev_render_single_event((int) $event->ID); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by renderer. ?>
    </main>

    <?php echo bgev_public_app_footer($calendarUrl, bgev_wording('event_footer_return', 'Return to the events calendar')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>

<?php wp_footer(); ?>
</body>
</html>
