<?php
/**
 * Theme-independent document for the site's front/home view.
 *
 * A block theme can keep its entire homepage (including the hero) in the
 * selected template rather than in a Page record. This renderer reuses that
 * template content, suppresses only the native navigation/identity/footer
 * blocks, and places the preserved hero and sections inside BosqueGrande's
 * shared frame. A canonical Loop is retained as a safe classic-theme fallback.
 */

defined('ABSPATH') || exit;

global $_wp_current_template_content;
$templateContent = is_string($_wp_current_template_content)
    ? trim($_wp_current_template_content)
    : '';
$queriedPage = get_queried_object();
$protectedPage = $queriedPage instanceof WP_Post
    && post_password_required($queriedPage);
$themes = array_values(array_unique(array_filter([get_stylesheet(), get_template()])));

// A static front page can be assigned a custom block template. Prefer that
// exact template (including a Site Editor override) before applying the normal
// front-page/home hierarchy. Classic .php assignments safely fall through to
// WordPress's already-resolved template content or the canonical Loop below.
if (!$protectedPage && function_exists('get_block_template') && $queriedPage instanceof WP_Post) {
    $assigned = str_replace('\\', '/', (string) get_page_template_slug($queriedPage));
    $assigned = (string) preg_replace('#^templates/#', '', trim($assigned, '/'));
    if (str_ends_with($assigned, '.html')) {
        $assigned = substr($assigned, 0, -5);
    } elseif (pathinfo($assigned, PATHINFO_EXTENSION) !== '') {
        $assigned = '';
    }
    if ($assigned !== ''
        && !str_contains($assigned, '..')
        && preg_match('#^[A-Za-z0-9/_-]+$#D', $assigned)) {
        foreach ($themes as $theme) {
            $candidate = get_block_template($theme . '//' . $assigned, 'wp_template');
            if ($candidate instanceof WP_Block_Template && trim((string) $candidate->content) !== '') {
                $templateContent = (string) $candidate->content;
                break;
            }
        }
    }
}

if (!$protectedPage && $templateContent === '' && function_exists('get_block_template')) {
    if (is_front_page() && (string) get_option('show_on_front') === 'page') {
        $front = $queriedPage;
        $slugs = ['front-page'];
        if ($front instanceof WP_Post) {
            if ($front->post_name !== '') $slugs[] = 'page-' . $front->post_name;
            $slugs[] = 'page-' . (int) $front->ID;
        }
        $slugs = array_merge($slugs, ['page', 'singular', 'index']);
    } elseif (is_front_page()) {
        $slugs = ['front-page', 'home', 'index'];
    } else {
        $slugs = ['home', 'index'];
    }
    $slugs = array_values(array_unique($slugs));
    foreach ($themes as $theme) {
        foreach ($slugs as $slug) {
            $candidate = get_block_template($theme . '//' . $slug, 'wp_template');
            if ($candidate instanceof WP_Block_Template && trim((string) $candidate->content) !== '') {
                $templateContent = (string) $candidate->content;
                break 2;
            }
        }
    }
}

$homeContent = '';
if (!$protectedPage && $templateContent !== '' && function_exists('do_blocks')) {
    $blockContains = static function (array $block, callable $matches) use (&$blockContains): bool {
        if ($matches($block)) return true;
        foreach ((array) ($block['innerBlocks'] ?? []) as $innerBlock) {
            if (is_array($innerBlock) && $blockContains($innerBlock, $matches)) return true;
        }
        return false;
    };
    $isHeaderContainer = static function (array $block): bool {
        $name = (string) ($block['blockName'] ?? '');
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $tag = sanitize_key((string) ($attrs['tagName'] ?? ''));
        if ($tag === 'header') return true;
        if ($name !== 'core/template-part') return false;
        $slug = sanitize_key((string) ($attrs['slug'] ?? ''));
        $area = sanitize_key((string) ($attrs['area'] ?? ''));
        return $area === 'header' || (bool) preg_match('/(^|[-_])header($|[-_])/', $slug);
    };
    $isFooterContainer = static function (array $block): bool {
        $name = (string) ($block['blockName'] ?? '');
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $tag = sanitize_key((string) ($attrs['tagName'] ?? ''));
        if ($tag === 'footer') return true;
        if ($name !== 'core/template-part') return false;
        $slug = sanitize_key((string) ($attrs['slug'] ?? ''));
        $area = sanitize_key((string) ($attrs['area'] ?? ''));
        return $area === 'footer' || (bool) preg_match('/(^|[-_])footer($|[-_])/', $slug);
    };

    // Keep a small render-depth scope while a standard header template part
    // or header-tagged group renders. Site identity and native navigation are
    // removed only inside that scope, preserving navigation blocks editors
    // intentionally place in the homepage body.
    $nativeChromeDepth = 0;
    $suppressNativeChrome = static function ($preRendered, array $block) use (
        $blockContains,
        $isHeaderContainer,
        $isFooterContainer,
        &$nativeChromeDepth
    ) {
        if ($preRendered !== null) return $preRendered;
        if ($isFooterContainer($block)) return '';

        $name = (string) ($block['blockName'] ?? '');
        $enteredHeader = $isHeaderContainer($block);
        if ($enteredHeader) $nativeChromeDepth++;

        if ($nativeChromeDepth > 0 && in_array($name, [
            'core/navigation',
            'core/site-logo',
            'core/site-title',
            'core/site-tagline',
        ], true)) {
            if ($enteredHeader) $nativeChromeDepth--;
            return '';
        }

        // The live block header keeps its logo/navigation and a standalone
        // Profile button in a layout wrapper above the hero. Remove the
        // nearest non-hero wrapper, rather than leaving an empty native band.
        if ($nativeChromeDepth > 0
            && in_array($name, ['core/group', 'core/columns', 'core/column'], true)) {
            $hasNavigation = $blockContains($block, static fn(array $candidate): bool =>
                (string) ($candidate['blockName'] ?? '') === 'core/navigation'
            );
            $hasHeroContent = $blockContains($block, static fn(array $candidate): bool =>
                in_array((string) ($candidate['blockName'] ?? ''), ['core/cover', 'core/heading'], true)
            );
            if ($hasNavigation && !$hasHeroContent) {
                if ($enteredHeader) $nativeChromeDepth--;
                return '';
            }
        }
        return $preRendered;
    };
    // The retained header template part contains the hero, but it must not
    // create a second page-level banner landmark beside the shared masthead.
    $demoteRetainedHeader = static function (string $rendered, array $block) use (
        $isHeaderContainer,
        &$nativeChromeDepth
    ): string {
        if (!$isHeaderContainer($block)) return $rendered;
        if ($nativeChromeDepth > 0) $nativeChromeDepth--;
        $rendered = (string) preg_replace('/^(\s*)<header\b/i', '$1<div', $rendered, 1);
        return (string) preg_replace('/<\/header>(\s*)$/i', '</div>$1', $rendered, 1);
    };
    add_filter('pre_render_block', $suppressNativeChrome, PHP_INT_MAX, 2);
    add_filter('render_block', $demoteRetainedHeader, PHP_INT_MAX, 2);
    try {
        $homeContent = (string) do_blocks($templateContent);
    } finally {
        remove_filter('pre_render_block', $suppressNativeChrome, PHP_INT_MAX);
        remove_filter('render_block', $demoteRetainedHeader, PHP_INT_MAX);
        $nativeChromeDepth = 0;
    }
    $homeContent = (string) preg_replace(
        '/Empowering\s+Connection\s+in\s+the\s+Northeast\.?/iu',
        '',
        $homeContent
    );
}

// Classic themes and incomplete block templates still receive the real query
// through the canonical Loop. Each unprotected post runs the_content once.
if (trim($homeContent) === '') {
    ob_start();
    if (have_posts()) {
        while (have_posts()) {
            the_post();
            $post = get_post();
            if (!$post instanceof WP_Post) continue;
            ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class('bg-site-article bg-site-article--home'); ?>>
                <div class="bg-site-content">
                    <?php if (post_password_required($post)) : ?>
                        <?php echo get_the_password_form($post); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core form. ?>
                    <?php else : ?>
                        <?php the_content(); ?>
                    <?php endif; ?>
                </div>
            </article>
            <?php
        }
    }
    $homeContent = (string) ob_get_clean();
}
$contentHasMain = (bool) preg_match('/<main\b/i', $homeContent);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (!current_theme_supports('title-tag')) : ?>
        <title><?php echo esc_html(wp_get_document_title()); ?></title>
    <?php endif; ?>
    <meta name="bosquegrande-shell-template" content="sitewide-home">
    <?php wp_head(); ?>
</head>
<body <?php body_class('bg-event-app-page bg-sitewide-page bg-sitewide-home'); ?>>
<?php wp_body_open(); ?>
<a class="bg-event-skip" href="#bg-site-main"><?php echo esc_html(bg_str('shell_skip_content', 'Skip to content')); ?></a>

<div class="bg-event-app-frame bg-site-page-frame">
    <?php echo bgev_public_app_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php if ($contentHasMain) : ?>
    <div id="bg-site-main" class="bg-event-app-main bg-site-main" tabindex="-1">
    <?php else : ?>
    <main id="bg-site-main" class="bg-event-app-main bg-site-main" tabindex="-1">
    <?php endif; ?>
        <div class="bg-site-surface bg-site-surface--home">
            <div class="bg-site-content bg-site-home-template">
                <?php echo $homeContent; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block/Loop renderers own escaping. ?>
            </div>
        </div>
    <?php if ($contentHasMain) : ?></div><?php else : ?></main><?php endif; ?>
    <?php echo bgev_public_app_footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>

<?php wp_footer(); ?>
</body>
</html>
