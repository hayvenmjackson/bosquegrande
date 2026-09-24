<?php
/**
 * Theme-independent document for ordinary WordPress pages and posts.
 *
 * The canonical Loop and the_content() run exactly once. This keeps block and
 * shortcode context intact while replacing only the active theme's document
 * chrome. Core block layout styles remain enqueued by GatheringTreeModule.
 */

defined('ABSPATH') || exit;

if (!have_posts()) return;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (!current_theme_supports('title-tag')) : ?>
        <title><?php echo esc_html(wp_get_document_title()); ?></title>
    <?php endif; ?>
    <meta name="bosquegrande-shell-template" content="sitewide-page">
    <?php wp_head(); ?>
</head>
<body <?php body_class('bg-event-app-page bg-sitewide-page'); ?>>
<?php wp_body_open(); ?>
<a class="bg-event-skip" href="#bg-site-main"><?php echo esc_html(bg_str('shell_skip_content', 'Skip to content')); ?></a>

<div class="bg-event-app-frame bg-site-page-frame">
    <?php echo bgev_public_app_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <main id="bg-site-main" class="bg-event-app-main bg-site-main" tabindex="-1">
        <div class="bg-site-surface">
        <?php while (have_posts()) : the_post();
            $post = get_post();
            if (!$post instanceof WP_Post) continue;
            $raw = (string) $post->post_content;
            // Resolve the normal content pipeline exactly once before deciding
            // whether the shared document needs to supply its own H1. A
            // shortcode can emit a page heading even when no H1 appears in
            // post_content itself.
            $protected = post_password_required($post);
            $rendered = $protected
                ? (string) get_the_password_form($post)
                : (string) apply_filters('the_content', $raw);
            $contentHasH1 = (bool) preg_match('/<h1\b/iu', $rendered);
            $articleClass = $post->post_type === 'post' ? 'bg-site-article bg-site-article--post' : 'bg-site-article bg-site-article--page';
            ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class($articleClass); ?>>
                <?php if (!$contentHasH1) : ?>
                    <header class="bg-site-page-heading">
                        <?php $prefix = bg_str('shell_site_title_prefix', ''); ?>
                        <?php if ($prefix !== '') : ?><p class="bg-public-app-eyebrow"><?php echo esc_html($prefix); ?></p><?php endif; ?>
                        <h1><?php echo esc_html(get_the_title()); ?></h1>
                        <?php if ($post->post_type === 'post') : ?>
                            <p class="bg-site-post-meta"><time datetime="<?php echo esc_attr(get_the_date(DATE_W3C)); ?>"><?php echo esc_html(get_the_date()); ?></time></p>
                        <?php endif; ?>
                    </header>
                <?php endif; ?>
                <div class="bg-site-content">
                    <?php echo $rendered; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core form/content pipeline owns escaping. ?>
                </div>
            </article>
        <?php endwhile; ?>
        </div>
    </main>
    <?php echo bgev_public_app_footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>

<?php wp_footer(); ?>
</body>
</html>
