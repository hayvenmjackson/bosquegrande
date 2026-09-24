<?php
/**
 * Public calendar, event pages, and event suggestion form.
 *
 * Global bgev_* functions are shared with the Registration, Admin,
 * Locations, and Media modules; markup uses the bgc-* class prefix.
 */

defined('ABSPATH') || exit;

if (!defined('BGEV_OPT')) define('BGEV_OPT', 'bg_calendar_settings');
if (!defined('BGEV_SUGGESTION_VERIFY_HOURS')) define('BGEV_SUGGESTION_VERIFY_HOURS', 6);

/** An event is past when its last day (end date if furnished, start
 *  date otherwise) precedes today. Dateless events are never "past". */
function bgev_event_is_past( $id ) {
    $last = get_post_meta( $id, '_bg_end_date', true ) ?: get_post_meta( $id, '_bg_start_date', true );
    if ( ! $last ) return false;
    return $last < current_time( 'Y-m-d' );
}

/** Region terms (slug => label) from the editable event vocabulary. */
function bgev_location_tags(): array {
    return \BosqueGrande\Modules\GatheringTree\Vocabulary::regions();
}

/** Interest terms (slug => label) from the editable event vocabulary. */
function bgev_interest_tags(): array {
    return \BosqueGrande\Modules\GatheringTree\Vocabulary::interests();
}

require_once __DIR__ . '/public-wording.php';

/** Record a calendar event in the shared activity log. */
function bgev_log( $m ) {
    \BosqueGrande\Core\Plugin::instance()->container()->get( 'logger' )->info( 'calendar', (string) $m );
}

function bgev_register_cpt() {
    register_post_type( 'bg_event', [
        'labels' => [
            'name' => 'Events', 'singular_name' => 'Event', 'add_new' => 'Add Event',
            'add_new_item' => 'Add Event', 'new_item' => 'New Event',
            'edit_item' => 'Edit Event', 'view_item' => 'View Event', 'view_items' => 'View Events',
            'search_items' => 'Search Events', 'not_found' => 'No events found',
            'not_found_in_trash' => 'No events found in Trash',
            'name_admin_bar' => 'Event',
            'archives' => 'Event Archives', 'attributes' => 'Event Attributes',
            'insert_into_item' => 'Insert into event', 'uploaded_to_this_item' => 'Uploaded to this event',
            'filter_items_list' => 'Filter events list', 'items_list_navigation' => 'Events list navigation',
            'items_list' => 'Events list', 'filter_by_date' => 'Filter events by date',
            'item_published' => 'Event published.', 'item_published_privately' => 'Event published privately.',
            'item_reverted_to_draft' => 'Event reverted to draft.', 'item_scheduled' => 'Event scheduled.',
            'item_updated' => 'Event updated.', 'item_link' => 'Event link',
            'item_link_description' => 'A link to an event.',
            'featured_image' => 'Event photo', 'set_featured_image' => 'Set event photo',
            'remove_featured_image' => 'Remove event photo', 'use_featured_image' => 'Use as event photo',
            // Explicit all_items so the sidebar reads "All Events"
            // under the "Events" section heading instead of Events twice
            // (WordPress reuses menu_name for all_items when unset).
            'all_items' => 'All Events', 'menu_name' => 'Events',
        ],
        'public' => true, 'has_archive' => true, 'show_in_menu' => true,
        'publicly_queryable' => true, 'menu_icon' => 'dashicons-calendar-alt',
        'supports' => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
        'rewrite' => [ 'slug' => 'events', 'with_front' => false ],
        'show_in_rest' => true,
    ] );
    if ( ! post_type_exists( 'mec-events' ) ) {
        register_post_type( 'mec-events', [ 'public' => false, 'show_in_menu' => false ] );
    }
    if ( ! taxonomy_exists( 'bg_tag' ) ) {
        register_taxonomy( 'bg_tag', 'bg_event', [
            'labels' => [ 'name' => 'Event Tags', 'singular_name' => 'Tag' ],
            'public' => true, 'hierarchical' => false, 'show_in_rest' => true,
            'rewrite' => [ 'slug' => 'event-tag' ],
        ] );
    }
}

/**
 * Use plain-language wording in third-party SEO fields on the event editor.
 * This changes display text only; the SEO plugin's meta keys and save
 * behavior remain untouched.
 */
function bgev_event_editor_plain_labels( $translated, $original ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'bg_event' ) return $translated;

    $labels = [
        'Meta Title'       => 'Optional Short Title',
        'Meta Description' => 'Short Description',
    ];
    return $labels[ $original ] ?? $translated;
}
add_filter( 'gettext', 'bgev_event_editor_plain_labels', 20, 2 );
add_filter( 'gettext_with_context', 'bgev_event_editor_plain_labels', 20, 2 );

function bgev_ensure_fixed_tags() {
    \BosqueGrande\Modules\GatheringTree\Vocabulary::ensureTerms();
}

function bgev_clean( $h ) {
    $h = preg_replace( '/<li[^>]*>\s*(?:&nbsp;|\s|<br\s*\/?>|<(?:span|a|p|em|strong|b|i|div)[^>]*>\s*(?:&nbsp;)?\s*<\/(?:span|a|p|em|strong|b|i|div)>)*\s*<\/li>/i', '', $h );
    $h = preg_replace( '/<(ul|ol)[^>]*>\s*<\/\1>/i', '', $h );
    return preg_replace( '/tockify-uid:[^\s<"\']+/i', '', $h );
}

/** URL identity for duplicate-image checks; ignores scheme and cache queries. */
function bgev_comparable_image_url( $url ) {
    $url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    if ( $url === '' ) return '';
    if ( str_starts_with( $url, '//' ) ) $url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
    elseif ( str_starts_with( $url, '/' ) ) $url = home_url( $url );
    $parts = wp_parse_url( $url );
    if ( ! is_array( $parts ) || empty( $parts['path'] ) ) return '';
    $host = strtolower( (string) ( $parts['host'] ?? wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) );
    $port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
    return $host . $port . rawurldecode( (string) $parts['path'] );
}

/**
 * Remove only inline copies of the image already promoted to the hero.
 * Other description images are event content and must remain in place.
 */
function bgev_remove_duplicate_hero_images( $content, $heroUrl ) {
    $content = (string) $content;
    $heroUrl = (string) $heroUrl;
    if ( $content === '' || $heroUrl === '' ) return $content;

    $heroComparable = bgev_comparable_image_url( $heroUrl );
    $heroAttachment = (int) attachment_url_to_postid( $heroUrl );
    $marker = '<!--bg-duplicate-hero-image-->';

    $content = preg_replace_callback( '/<img\b[^>]*>/i', static function ( array $match ) use ( $heroComparable, $heroAttachment, $marker ): string {
        $tag = (string) $match[0];
        if ( ! preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/is', $tag, $srcMatch ) ) return $tag;
        $src = html_entity_decode( trim( (string) $srcMatch[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( $src === '' ) return $tag;

        $sameUrl = $heroComparable !== '' && bgev_comparable_image_url( $src ) === $heroComparable;
        $inlineAttachment = 0;
        if ( preg_match( '/\bwp-image-(\d+)\b/i', $tag, $idMatch )
            || preg_match( '/\bdata-attachment-id\s*=\s*["\'](\d+)["\']/i', $tag, $idMatch ) ) {
            $inlineAttachment = (int) $idMatch[1];
        }
        if ( ! $inlineAttachment && $heroAttachment ) {
            $inlineAttachment = (int) attachment_url_to_postid( $src );
        }
        $sameAttachment = $heroAttachment > 0 && $inlineAttachment === $heroAttachment;
        return ( $sameUrl || $sameAttachment ) ? $marker : $tag;
    }, $content );

    // Remove wrappers only when their sole meaningful child was the duplicate.
    $quoted = preg_quote( $marker, '/' );
    $content = preg_replace( '/<a\b[^>]*>\s*' . $quoted . '\s*<\/a>/i', $marker, (string) $content );
    $content = preg_replace( '/<p\b[^>]*>\s*(?:&nbsp;|<br\s*\/?>|\s)*' . $quoted . '(?:&nbsp;|<br\s*\/?>|\s)*<\/p>/i', '', (string) $content );
    $content = preg_replace( '/<figure\b[^>]*>\s*' . $quoted . '\s*<\/figure>/i', '', (string) $content );
    return str_replace( $marker, '', (string) $content );
}

function bgev_img( $id ) {
    $s = get_option( BGEV_OPT, [] );
    $mode = $s['image_source'] ?? 'auto';
    if ( $mode === 'featured' || $mode === 'download' ) {
        $thumb_id = get_post_thumbnail_id( $id );
        if ( $thumb_id ) {
            $url = wp_get_attachment_image_url( $thumb_id, 'large' );
            if ( $url ) return $url;
        }
        // Featured mode: no fallback, show placeholder
        // Download mode: fall back to _bg_image (in case download failed or hasn't run yet)
        if ( $mode === 'download' ) return get_post_meta( $id, '_bg_image', true ) ?: '';
        return '';
    }
    // 'auto' mode
    return get_post_meta( $id, '_bg_image', true ) ?: '';
}

function bgev_reg( $id ) {
    // Ask the Registration module directly. The old path (a filter
    // over the meta read) could be bypassed by page caches of rendered
    // HTML and left buttons absent or stale; this cannot.
    if ( class_exists( '\\BosqueGrande\\Modules\\Registration\\RegistrationModule' )
        && apply_filters( 'bg_native_registration', true ) ) {
        return home_url( '/bg-register/' . (int) $id . '/' );
    }
    return get_post_meta( $id, '_bg_reg_url', true ) ?: '';
}

function bgev_date( $id ) {
    $d = get_post_meta( $id, '_bg_start_date', true );
    if ( ! $d ) return '';
    $ts = strtotime( $d );
    return $ts ? date_i18n( 'l, F j, Y', $ts ) : $d;
}

/** Normalize the calendar's several historical time formats for display. */
function bgev_format_time_value( $raw, $format = 'g:i A' ) {
    $raw = trim( (string) $raw );
    if ( $raw === '' ) return '';
    $formats = [ 'g:i A', 'g A', 'H:i', 'H:i:s', 'h:i A', 'h:iA', 'g:iA' ];
    foreach ( $formats as $candidate ) {
        $dt = DateTimeImmutable::createFromFormat( '!' . $candidate, strtoupper( $raw ), wp_timezone() );
        $errors = DateTimeImmutable::getLastErrors();
        if ( $dt && ( ! is_array( $errors ) || ( $errors['warning_count'] === 0 && $errors['error_count'] === 0 ) ) ) {
            return $dt->format( $format );
        }
    }
    $ts = strtotime( $raw );
    return $ts !== false ? wp_date( $format, $ts, wp_timezone() ) : $raw;
}

function bgev_time( $id ) {
    return bgev_format_time_value( get_post_meta( $id, '_bg_start_time', true ) );
}

/**
 * End time comes from the editor or a sync. Events without one display a
 * one-hour duration rather than no end time.
 */
function bgev_end_time( $id ) {
    $explicit = get_post_meta( $id, '_bg_end_time', true );
    if ( trim( (string) $explicit ) !== '' ) return bgev_format_time_value( $explicit );
    $start = get_post_meta( $id, '_bg_start_time', true );
    $start24 = bgev_format_time_value( $start, 'H:i' );
    if ( ! preg_match( '/^\d{2}:\d{2}$/', $start24 ) ) return '';
    $dt = DateTimeImmutable::createFromFormat( '!H:i', $start24, wp_timezone() );
    return $dt ? $dt->modify( '+1 hour' )->format( 'g:i A' ) : '';
}

function bgev_time_range( $id ) {
    $start = bgev_time( $id );
    $end = bgev_end_time( $id );
    if ( $start && $end ) return $start . ' – ' . $end;
    return $start ?: $end;
}

/** Value suitable for <input type="time">. */
function bgev_time_input_value( $raw ) {
    $value = bgev_format_time_value( $raw, 'H:i' );
    return preg_match( '/^\d{2}:\d{2}$/', $value ) ? $value : '';
}

/** Normalize an admin-entered browser time to the calendar's display format. */
function bgev_time_storage_value( $raw ) {
    $raw = sanitize_text_field( (string) $raw );
    if ( $raw === '' ) return '';
    return bgev_format_time_value( $raw );
}

function bg_location_taxonomy() {
    return class_exists( '\\BosqueGrande\\Modules\\Locations\\Taxonomy' )
        ? \BosqueGrande\Modules\Locations\Taxonomy::taxonomy()
        : 'bg_tag';
}

function bg_tags( $id ) {
    $ts = wp_get_post_terms( $id, array_values( array_unique( [ 'bg_tag', bg_location_taxonomy() ] ) ) );
    if ( is_wp_error( $ts ) ) return [];
    $valid = array_merge( array_keys( bgev_location_tags() ), array_keys( bgev_interest_tags() ) );
    return array_values( array_filter( $ts, function( $t ) use ( $valid ) { return in_array( $t->slug, $valid ); } ) );
}

function bgev_search_state( $set = null ) {
    static $state = [ 'active' => false, 'strict' => true, 'query' => '' ];
    if ( $set !== null ) $state = $set;
    return $state;
}

/**
 * Match the same effective last day used by bgev_event_is_past(): explicit
 * end date when furnished, otherwise start date.
 */
function bgev_effective_last_date_meta_query( $today, $compare ) {
    return [
        'relation' => 'OR',
        [
            'key' => '_bg_end_date',
            'value' => (string) $today,
            'compare' => (string) $compare,
            'type' => 'DATE',
        ],
        [
            'relation' => 'AND',
            [
                'relation' => 'OR',
                [ 'key' => '_bg_end_date', 'compare' => 'NOT EXISTS' ],
                [ 'key' => '_bg_end_date', 'value' => '', 'compare' => '=' ],
            ],
            [
                'key' => '_bg_start_date',
                'value' => (string) $today,
                'compare' => (string) $compare,
                'type' => 'DATE',
            ],
        ],
    ];
}

function bgev_qargs( $o = [] ) {
    $o = wp_parse_args( $o, [ 'pp' => 12, 'pg' => 1, 'loc' => '', 'int' => '', 'mo' => '', 'yr' => '', 'date' => '', 'scope' => 'upcoming' ] );
    // Scope selects the time window. upcoming (default, unchanged
    // behavior), past (before today, most recent first), all (every dated
    // event). Adopted historical events surface through past and all.
    $scope = in_array( $o['scope'], [ 'upcoming', 'past', 'all' ], true ) ? $o['scope'] : 'upcoming';
    $today = current_time( 'Y-m-d' );
    if ( $scope === 'past' ) {
        $mq = [ bgev_effective_last_date_meta_query( $today, '<' ) ];
        $order = 'DESC';
    } elseif ( $scope === 'all' ) {
        $mq = [ [ 'key' => '_bg_start_date', 'compare' => 'EXISTS' ] ];
        $order = 'DESC';
    } else {
        $mq = [ bgev_effective_last_date_meta_query( $today, '>=' ) ];
        $order = 'ASC';
    }
    $a = [
        // Additional event post types may join through this compatibility
        // filter. Adopt Events remains the supported route because adopted
        // records receive the complete event feature set.
        'post_type'      => apply_filters( 'bg_calendar_post_types', [ 'bg_event' ] ),
        'post_status'    => 'publish',
        'posts_per_page' => (int) $o['pp'],
        'paged'          => (int) $o['pg'],
        'meta_key'       => '_bg_start_date',
        'orderby'        => 'meta_value',
        'order'          => $order,
        'meta_query'     => $mq,
    ];
    // Location + interest can both be active simultaneously
    if ( $o['loc'] && $o['int'] ) {
        $a['tax_query'] = [
            'relation' => 'AND',
            [ 'taxonomy' => bg_location_taxonomy(), 'field' => 'slug', 'terms' => $o['loc'] ],
            [ 'taxonomy' => 'bg_tag', 'field' => 'slug', 'terms' => $o['int'] ],
        ];
    } elseif ( $o['loc'] ) {
        $a['tax_query'] = [ [ 'taxonomy' => bg_location_taxonomy(), 'field' => 'slug', 'terms' => $o['loc'] ] ];
    } elseif ( $o['int'] ) {
        $a['tax_query'] = [ [ 'taxonomy' => 'bg_tag', 'field' => 'slug', 'terms' => $o['int'] ] ];
    }
    // Single-day filter; overrides the default >= today filter
    if ( $o['date'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $o['date'] ) ) {
        $a['posts_per_page'] = 50;
        $a['meta_query'] = [ [
            'key'     => '_bg_start_date',
            'value'   => $o['date'],
            'compare' => '=',
            'type'    => 'DATE',
        ] ];
    } elseif ( $o['mo'] && $o['yr'] ) {
        $ms = sprintf( '%04d-%02d-01', $o['yr'], $o['mo'] );
        $a['posts_per_page'] = 200;
        $a['meta_query'] = [ [
            'key'     => '_bg_start_date',
            'value'   => [ $ms, date( 'Y-m-t', strtotime( $ms ) ) ],
            'compare' => 'BETWEEN',
            'type'    => 'DATE',
        ] ];
    }
    // Gathering Tree Search. When a query is present, restrict the
    // result set to matching entries. Matching is fixed-rule (Search
    // class); date ordering & every other filter continue to apply.
    $sq = trim( sanitize_text_field( wp_unslash( $_GET['bgev_q'] ?? '' ) ) );
    if ( $sq !== '' ) {
        $probe = $a;
        $probe['posts_per_page'] = -1;
        $probe['fields'] = 'ids';
        unset( $probe['paged'] );
        $candidates = ( new WP_Query( $probe ) )->posts;
        $candidates = array_map( 'intval', is_array( $candidates ) ? $candidates : [] );
        $res = \BosqueGrande\Modules\GatheringTree\Search::run( $sq, $candidates );
        bgev_search_state( [ 'active' => true, 'strict' => (bool) $res['strict'], 'query' => $sq ] );
        $a['post__in'] = $res['ids'] ? $res['ids'] : [ 0 ];
    }

    return $a;
}

function bgev_current_filters(): array {
    $loc = sanitize_text_field( $_GET['bgev_loc'] ?? '' );
    $int = sanitize_text_field( $_GET['bgev_int'] ?? '' );
    return [ 'loc' => $loc, 'int' => $int ];
}

function bgev_current_base() {
    $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
    $path = strtok( $path, '?' );
    return home_url( $path );
}

/**
 * Canonical, sanitized calendar query state. Only recognized keys are
 * re-emitted, so a cleared filter never comes back from an old URL.
 *
 * @param array<int,string> $omit
 * @return array<string,string|int>
 */
function bgev_calendar_state_args( array $omit = [] ): array {
    $filters = bgev_current_filters();
    $has_view = array_key_exists( 'bg_view', $_GET );
    $has_scope = array_key_exists( 'bg_scope', $_GET );
    $has_month = array_key_exists( 'bg_month', $_GET );
    $has_year = array_key_exists( 'bg_year', $_GET );
    $view = sanitize_key( wp_unslash( (string) ( $_GET['bg_view'] ?? '' ) ) );
    if ( ! in_array( $view, [ 'pinboard', 'calendar', 'list' ], true ) ) $view = 'pinboard';
    $scope = sanitize_key( wp_unslash( (string) ( $_GET['bg_scope'] ?? '' ) ) );
    if ( ! in_array( $scope, [ 'upcoming', 'past', 'all' ], true ) ) $scope = 'upcoming';
    $query = trim( sanitize_text_field( wp_unslash( (string) ( $_GET['bgev_q'] ?? '' ) ) ) );
    $date = sanitize_text_field( wp_unslash( (string) ( $_GET['bg_date'] ?? '' ) ) );
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) $date = '';

    $args = [];
    // When the URL has no view/scope state, allow shortcode attributes to
    // remain authoritative, so [bg_calendar view="list" scope="past"]
    // keeps its settings across search and filter actions.
    if ( $has_view ) $args['bg_view'] = $view;
    if ( $filters['loc'] !== '' ) $args['bgev_loc'] = $filters['loc'];
    if ( $filters['int'] !== '' ) $args['bgev_int'] = $filters['int'];
    if ( $query !== '' ) $args['bgev_q'] = $query;
    if ( $has_scope && ( ! $has_view || $view !== 'calendar' ) ) $args['bg_scope'] = $scope;
    if ( ( $has_view && $view === 'calendar' ) || $has_month || $has_year ) {
        $month = max( 1, min( 12, (int) ( $_GET['bg_month'] ?? current_time( 'n' ) ) ) );
        $year = (int) ( $_GET['bg_year'] ?? current_time( 'Y' ) );
        $year = max( 2020, min( (int) current_time( 'Y' ) + 20, $year ) );
        $args['bg_month'] = $month;
        $args['bg_year'] = $year;
    }
    if ( $date !== '' && $view !== 'calendar' ) $args['bg_date'] = $date;

    foreach ( $omit as $key ) unset( $args[ (string) $key ] );
    return $args;
}

/** Build a canonical calendar URL while changing only named state. */
function bgev_calendar_state_url( array $overrides = [], array $omit = [] ): string {
    $args = bgev_calendar_state_args( $omit );
    foreach ( $overrides as $key => $value ) {
        $key = sanitize_key( (string) $key );
        if ( $key === '' ) continue;
        if ( $value === '' || $value === null || $value === false ) unset( $args[ $key ] );
        else $args[ $key ] = is_int( $value ) ? $value : sanitize_text_field( (string) $value );
    }
    return add_query_arg( $args, bgev_current_base() );
}

/** Current calendar URL, including the visitor's view, filters, month, and search. */
function bgev_current_calendar_url(): string {
    $fallback = (string) apply_filters( 'bg_calendar_return_url', bgev_public_calendar_page_url() ?: home_url( '/' ) );
    $request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
    if ( $request === '' || $request[0] !== '/' ) return $fallback;
    return wp_validate_redirect( home_url( $request ), $fallback );
}

/** Same-site return supplied by a calendar link, falling back to the calendar page. */
function bgev_calendar_return_url(): string {
    $fallback = (string) apply_filters( 'bg_calendar_return_url', bgev_public_calendar_page_url() ?: home_url( '/' ) );
    $candidate = isset( $_GET['bg_return'] )
        ? esc_url_raw( wp_unslash( (string) $_GET['bg_return'] ) )
        : '';
    $validated = wp_validate_redirect( $candidate, $fallback );
    return remove_query_arg( 'bg_return', $validated );
}

function bgev_add_calendar_return( string $url, string $returnUrl ): string {
    $targetHost = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    $homeHost = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
    if ( $targetHost !== '' && $homeHost !== '' && $targetHost !== $homeHost ) return $url;
    return add_query_arg( 'bg_return', $returnUrl, $url );
}

function bgev_registration_link( int $id ): string {
    $url = bgev_reg( $id );
    if ( $url === '' ) return '';
    $returnUrl = isset( $_GET['bg_return'] )
        ? bgev_calendar_return_url()
        : bgev_current_calendar_url();
    return bgev_add_calendar_return( $url, $returnUrl );
}

function bgev_get_submission_url(): string {
    $s = get_option( BGEV_OPT, [] );
    $mode = $s['submission_link_mode'] ?? 'anchor';

    if ( $mode === 'disabled' ) return '';
    // The primary [bosquegrande] shortcode is the complete public
    // application. Its Suggest button always targets the form in the same
    // frame; [bg_calendar] follows the configured submission-page setting.
    if ( bgev_composite_app_context() ) return '#bgc-submit-form';
    if ( $mode === 'anchor' ) return '#bgc-submit-form';

    // 'page' mode
    $page_id = (int) ( $s['submission_page_id'] ?? 0 );
    if ( $page_id ) {
        $page = get_post( $page_id );
        if ( $page && $page->post_type === 'page' && $page->post_status === 'publish' ) {
            return home_url( '/?page_id=' . $page_id );
        }
    }
    return (string) ( $s['submission_page_url'] ?? '' );
}

/** Whether the complete [bosquegrande] apparatus is currently rendering. */
function bgev_composite_app_context( ?bool $set = null ): bool {
    static $active = false;
    if ( $set !== null ) $active = $set;
    return $active;
}

/** Whether a normal WordPress page contains the new complete application. */
function bgev_page_has_complete_app( ?WP_Post $post = null ): bool {
    return bgev_public_app_page_context( $post ) === 'calendar';
}

/** Identify only direct, ordinary pages BosqueGrande can safely own. */
function bgev_public_app_page_context( ?WP_Post $post = null ): string {
    $post = $post ?: get_post();
    if ( ! $post instanceof WP_Post || $post->post_type !== 'page' ) return '';
    $content = (string) $post->post_content;
    if ( has_shortcode( $content, 'bosquegrande' ) ) return 'calendar';
    if ( has_shortcode( $content, 'bosquegrande_profile' ) ) return 'profile';
    return '';
}

/**
 * Rendering context set only by the plugin-owned page document. Shortcodes
 * can also be embedded elsewhere; in that case they emit their own complete
 * frame instead of assuming the active theme will provide one.
 */
function bgev_public_document_context( ?string $set = null ): string {
    static $context = '';
    if ( $set !== null ) $context = sanitize_key( $set );
    return $context;
}

/** Resolve the public My Profile page without assuming a particular slug. */
function bgev_public_profile_url(): string {
    static $resolved = null;
    if ( is_string( $resolved ) ) return $resolved;

    $fallback = home_url( '/my-profile/' );
    $configured = bgev_public_nav_editor_config();
    $candidate = bgev_public_url( (string) ( $configured['profile_url'] ?? '' ) );
    if ( $candidate === '' ) {
        global $wpdb;
        $pageId = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s
             ORDER BY ID ASC LIMIT 1",
            '%[' . $wpdb->esc_like( 'bosquegrande_profile' ) . '%'
        ) );
        $page = $pageId > 0 ? get_post( $pageId ) : null;
        if ( $page instanceof WP_Post && has_shortcode( (string) $page->post_content, 'bosquegrande_profile' ) ) {
            $candidate = (string) get_permalink( $page );
        }
    }
    $filtered = (string) apply_filters( 'bg_my_profile_url', $candidate );
    $resolved = wp_validate_redirect( $filtered !== '' ? $filtered : $fallback, $fallback );
    return $resolved;
}

/** Resolve the original shortcode-based Organizer Portal, not the organizer PWA. */
function bgev_public_organizer_portal_url(): string {
    static $resolved = null;
    if ( is_string( $resolved ) ) return $resolved;

    $pageId = (int) get_option( 'bg_organizer_portal_page_id', 0 );
    $page = $pageId > 0 ? get_post( $pageId ) : null;
    if ( ! $page instanceof WP_Post
        || $page->post_type !== 'page'
        || $page->post_status !== 'publish'
        || ! has_shortcode( (string) $page->post_content, 'bg_organizer_portal' ) ) {
        global $wpdb;
        $pageId = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s
             ORDER BY ID ASC LIMIT 1",
            '%[' . $wpdb->esc_like( 'bg_organizer_portal' ) . '%'
        ) );
        $page = $pageId > 0 ? get_post( $pageId ) : null;
        if ( ! $page instanceof WP_Post
            || $page->post_type !== 'page'
            || $page->post_status !== 'publish'
            || ! has_shortcode( (string) $page->post_content, 'bg_organizer_portal' ) ) {
            $page = null;
        }
    }

    $fallback = home_url( '/organizer-portal/' );
    $candidate = $page instanceof WP_Post ? (string) get_permalink( $page ) : '';
    $resolved = wp_validate_redirect( $candidate !== '' ? $candidate : $fallback, $fallback );
    return $resolved;
}

/** Compare only same-site paths when identifying the current masthead item. */
function bgev_public_nav_is_current( string $url ): bool {
    $request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
    $targetHost = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    $siteHost = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
    if ( $targetHost !== '' && $targetHost !== $siteHost ) return false;
    $currentPath = (string) wp_parse_url( $request, PHP_URL_PATH );
    $targetPath = (string) wp_parse_url( $url, PHP_URL_PATH );
    if ( untrailingslashit( $currentPath ) !== untrailingslashit( $targetPath ) ) return false;
    $targetQuery = [];
    $requestQuery = [];
    parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $targetQuery );
    parse_str( (string) wp_parse_url( $request, PHP_URL_QUERY ), $requestQuery );
    if ( $targetQuery !== [] ) {
        foreach ( $targetQuery as $key => $value ) {
            if ( ! array_key_exists( $key, $requestQuery ) || (string) $requestQuery[ $key ] !== (string) $value ) return false;
        }
        return true;
    }
    // Query-var application routes live at the site root. They must not make
    // the ordinary Home link current merely because their path is also '/'.
    foreach ( [ 'bg_donation', 'bg_monthly_donation', 'bg_register' ] as $routeKey ) {
        if ( isset( $requestQuery[ $routeKey ] ) ) return false;
    }
    return true;
}

/** Convert an editable same-site path or absolute URL into a public URL. */
function bgev_public_url( string $url ): string {
    $url = trim( $url );
    if ( $url === '' ) return '';
    if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
        return home_url( '/' . ltrim( $url, '/' ) );
    }
    return (string) esc_url_raw( $url, [ 'http', 'https' ] );
}

/** Stored public-shell settings: the two call-to-action destinations. */
function bgev_public_nav_editor_config(): array {
    $saved = get_option( 'bg_public_navigation', [] );
    if ( ! is_array( $saved ) ) $saved = [];
    return [
        'profile_url' => (string) ( $saved['profile_url'] ?? '' ),
        'donation_url' => (string) ( $saved['donation_url'] ?? '' ),
        'show_profile' => array_key_exists( 'show_profile', $saved ) ? (int) ! empty( $saved['show_profile'] ) : 1,
        'show_donation' => array_key_exists( 'show_donation', $saved ) ? (int) ! empty( $saved['show_donation'] ) : 1,
    ];
}

/**
 * Navigation for BosqueGrande's own public pages. The site's WordPress menu
 * assigned to the "BosqueGrande header" location is used when present (two
 * levels deep); otherwise a short default of Home and the events calendar.
 *
 * @return array<int,array<string,mixed>>
 */
function bgev_public_nav_tree(): array {
    $tree = [];
    $locations = get_nav_menu_locations();
    $menuId = (int) ( $locations['bosquegrande_header'] ?? 0 );
    $items = $menuId > 0 ? wp_get_nav_menu_items( $menuId, [ 'update_post_term_cache' => false ] ) : false;
    if ( is_array( $items ) && $items ) {
        $byParent = [];
        foreach ( $items as $menuItem ) {
            if ( ! $menuItem instanceof WP_Post ) continue;
            $byParent[ (int) $menuItem->menu_item_parent ][] = $menuItem;
        }
        foreach ( (array) ( $byParent[0] ?? [] ) as $top ) {
            $children = [];
            foreach ( (array) ( $byParent[ (int) $top->ID ] ?? [] ) as $child ) {
                $children[] = [
                    'key' => 'menu-' . (int) $child->ID,
                    'label' => (string) $child->title,
                    'url' => (string) $child->url,
                    'enabled' => 1,
                ];
            }
            $tree[] = [
                'key' => 'menu-' . (int) $top->ID,
                'label' => (string) $top->title,
                'url' => (string) $top->url,
                'enabled' => 1,
                'children' => $children,
            ];
        }
    } else {
        $tree[] = [ 'key' => 'home', 'label' => bg_str( 'nav_home', 'Home' ), 'url' => home_url( '/' ), 'enabled' => 1, 'children' => [] ];
        $calendar = bgev_public_calendar_page_url();
        if ( $calendar !== '' ) {
            $tree[] = [ 'key' => 'events', 'label' => bg_str( 'nav_events', 'Events' ), 'url' => $calendar, 'enabled' => 1, 'children' => [] ];
        }
    }
    return (array) apply_filters( 'bg_public_navigation_tree', $tree );
}

/** The first published page carrying the complete calendar app, if any. */
function bgev_public_calendar_page_url(): string {
    static $resolved = null;
    if ( is_string( $resolved ) ) return $resolved;
    global $wpdb;
    $pageId = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s
         ORDER BY ID ASC LIMIT 1",
        '%[' . $wpdb->esc_like( 'bosquegrande' ) . '%'
    ) );
    $page = $pageId > 0 ? get_post( $pageId ) : null;
    $resolved = $page instanceof WP_Post && has_shortcode( (string) $page->post_content, 'bosquegrande' )
        ? (string) get_permalink( $page )
        : (string) get_post_type_archive_link( 'bg_event' );
    return $resolved;
}

/** Online giving needs Stripe keys; without them the Donate action is hidden. */
function bgev_public_donations_ready(): bool {
    try {
        $container = \BosqueGrande\Core\Plugin::instance()->container();
        return $container->has( 'stripe' ) && $container->get( 'stripe' )->isConfigured();
    } catch ( \Throwable $e ) {
        return false;
    }
}

/** Stable target for the combined giving form. */
function bgev_public_donation_url(): string {
    $fallback = home_url( '/bg-donation/' );
    $config = bgev_public_nav_editor_config();
    $configured = bgev_public_url( (string) ( $config['donation_url'] ?? '' ) );
    $candidate = (string) apply_filters(
        'bg_combined_donation_url',
        $configured !== '' ? $configured : $fallback
    );
    return wp_validate_redirect( $candidate, $fallback );
}

/** Whether a link or one of its descendants identifies this request. */
function bgev_public_nav_item_is_ancestor( array $item ): bool {
    if ( bgev_public_nav_is_current( (string) ( $item['url'] ?? '' ) ) ) return true;
    if ( (string) ( $item['key'] ?? '' ) === 'events' && is_singular( 'bg_event' ) ) return true;
    foreach ( (array) ( $item['children'] ?? [] ) as $child ) {
        if ( bgev_public_nav_is_current( (string) ( $child['url'] ?? '' ) ) ) return true;
    }
    return false;
}

/** Shared masthead: the organization's logo or name, the menu, and two actions. */
function bgev_public_app_header( string $calendarUrl = '' ): string {
    $config = bgev_public_nav_editor_config();
    $tree = bgev_public_nav_tree();
    // A destination, not display wording: registration passes the calendar
    // view the visitor came from so "Events" returns them to it.
    if ( $calendarUrl !== '' ) {
        foreach ( $tree as &$item ) {
            if ( (string) ( $item['key'] ?? '' ) === 'events' ) $item['url'] = $calendarUrl;
        }
        unset( $item );
    }
    $homeUrl = home_url( '/' );
    $orgName = \BosqueGrande\Core\Organization::name();
    $logoUrl = \BosqueGrande\Core\Organization::logoUrl();
    $profileUrl = bgev_public_profile_url();
    $donationUrl = bgev_public_donation_url();
    $showProfile = ! empty( $config['show_profile'] );
    $showDonation = ! empty( $config['show_donation'] ) && bgev_public_donations_ready();
    $navLabel = bg_str( 'nav_accessible_label', 'Primary site navigation' );

    ob_start();
    ?>
    <header class="bg-event-appbar">
        <div class="bg-event-appbar-inner">
            <div class="bg-event-identities bg-site-identity">
                <a class="bg-org-brand<?php echo $logoUrl === '' ? ' bg-org-brand--text' : ''; ?>" href="<?php echo esc_url( $homeUrl ); ?>" aria-label="<?php echo esc_attr( bg_str_format( 'nav_logo_home', '{org} home', [ 'org' => $orgName ] ) ); ?>">
                    <?php if ( $logoUrl !== '' ) : ?>
                        <img src="<?php echo esc_url( $logoUrl ); ?>" width="56" height="56" alt="" decoding="async">
                    <?php else : ?>
                        <span class="bg-org-name"><?php echo esc_html( $orgName ); ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <button class="bg-site-nav-toggle" type="button" aria-expanded="false" aria-controls="bg-site-nav-wrap"><?php echo esc_html( bg_str( 'nav_menu_button', 'Menu' ) ); ?></button>
            <div class="bg-site-nav-wrap" id="bg-site-nav-wrap">
                <nav class="bg-event-appnav" aria-label="<?php echo esc_attr( $navLabel ); ?>">
                    <ul class="bg-site-nav-list">
                    <?php foreach ( $tree as $item ) :
                        $children = (array) ( $item['children'] ?? [] );
                        $ancestor = bgev_public_nav_item_is_ancestor( $item );
                        $isCurrent = bgev_public_nav_is_current( (string) $item['url'] ); ?>
                        <li class="bg-site-nav-item<?php echo $children ? ' bg-site-nav-item--parent' : ''; ?><?php echo $ancestor ? ' is-current-ancestor' : ''; ?>">
                            <a class="bg-event-appbar-link bg-event-appbar-link--<?php echo esc_attr( sanitize_html_class( (string) $item['key'] ) ); ?>" href="<?php echo esc_url( (string) $item['url'] ); ?>"<?php echo $isCurrent ? ' aria-current="page"' : ''; ?>><?php echo esc_html( (string) $item['label'] ); ?></a>
                            <?php if ( $children ) :
                                $submenuId = 'bg-submenu-' . sanitize_html_class( (string) $item['key'] );
                                $openLabel = bg_str_format(
                                    'nav_submenu_open',
                                    'Open {item} menu',
                                    [ 'item' => (string) $item['label'] ]
                                ); ?>
                                <button class="bg-site-submenu-toggle" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr( $submenuId ); ?>" aria-label="<?php echo esc_attr( $openLabel ); ?>"><span aria-hidden="true">&#9662;</span></button>
                                <ul class="bg-site-submenu" id="<?php echo esc_attr( $submenuId ); ?>">
                                <?php foreach ( $children as $child ) : $childCurrent = bgev_public_nav_is_current( (string) $child['url'] ); ?>
                                    <li><a href="<?php echo esc_url( (string) $child['url'] ); ?>"<?php echo $childCurrent ? ' aria-current="page"' : ''; ?>><?php echo esc_html( (string) $child['label'] ); ?></a></li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </nav>
                <?php if ( $showProfile || $showDonation ) : ?>
                <div class="bg-site-nav-ctas">
                    <?php if ( $showProfile ) : ?>
                    <a class="bg-event-appbar-link bg-event-appbar-link--profile" href="<?php echo esc_url( $profileUrl ); ?>"<?php echo bgev_public_nav_is_current( $profileUrl ) ? ' aria-current="page"' : ''; ?>><?php echo esc_html( bg_str( 'nav_profile_cta', 'My Profile' ) ); ?></a>
                    <?php endif; ?>
                    <?php if ( $showDonation ) : ?>
                    <a class="bg-event-appbar-link bg-event-appbar-link--donate" href="<?php echo esc_url( $donationUrl ); ?>"<?php echo bgev_public_nav_is_current( $donationUrl ) ? ' aria-current="page"' : ''; ?>><?php echo esc_html( bg_str( 'nav_donate_cta', 'Donate' ) ); ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <script>
    (function(){
      var script=document.currentScript,header=script&&script.previousElementSibling;
      if(!header||!header.classList.contains('bg-event-appbar'))return;
      header.classList.add('bg-nav-enhanced');
      var menu=header.querySelector('.bg-site-nav-toggle'),wrap=header.querySelector('.bg-site-nav-wrap');
      if(menu&&wrap)menu.addEventListener('click',function(){var open=menu.getAttribute('aria-expanded')==='true';menu.setAttribute('aria-expanded',open?'false':'true');header.classList.toggle('is-menu-open',!open);});
      header.querySelectorAll('.bg-site-submenu-toggle').forEach(function(button){
        button.addEventListener('click',function(){
          var open=button.getAttribute('aria-expanded')==='true';
          header.querySelectorAll('.bg-site-submenu-toggle[aria-expanded="true"]').forEach(function(other){if(other!==button)other.setAttribute('aria-expanded','false');});
          button.setAttribute('aria-expanded',open?'false':'true');
        });
      });
      header.addEventListener('keydown',function(event){
        if(event.key!=='Escape')return;
        var submenu=header.querySelector('.bg-site-submenu-toggle[aria-expanded="true"]');
        header.querySelectorAll('.bg-site-submenu-toggle[aria-expanded="true"]').forEach(function(button){button.setAttribute('aria-expanded','false');});
        if(menu&&menu.getAttribute('aria-expanded')==='true'){
          menu.setAttribute('aria-expanded','false');header.classList.remove('is-menu-open');menu.focus();
        }else if(submenu){submenu.focus();}
      });
      document.addEventListener('click',function(event){if(header.contains(event.target))return;header.querySelectorAll('.bg-site-submenu-toggle[aria-expanded="true"]').forEach(function(button){button.setAttribute('aria-expanded','false');});});
    }());
    </script>
    <?php
    return (string) ob_get_clean();
}

/** Shared application footer. */
function bgev_public_app_footer( string $primaryUrl = '', string $primaryLabel = '' ): string {
    $orgName = \BosqueGrande\Core\Organization::name();
    ob_start();
    ?>
    <footer class="bg-event-app-footer">
        <?php if ( $primaryUrl !== '' && $primaryLabel !== '' ) : ?>
            <p class="bg-app-footer-nav"><a href="<?php echo esc_url( $primaryUrl ); ?>"><?php echo esc_html( $primaryLabel ); ?></a></p>
        <?php endif; ?>
        <p class="bg-footer-org">&copy; <?php echo esc_html( wp_date( 'Y' ) . ' ' . $orgName ); ?></p>
        <?php if ( \BosqueGrande\Core\Organization::showAttribution() ) : ?>
            <p class="bg-platform-label"><?php echo esc_html( bg_str( 'footer_platform_label', 'Powered by BosqueGrande' ) ); ?></p>
        <?php endif; ?>
    </footer>
    <?php
    return (string) ob_get_clean();
}

/**
 * Theme-independent visual frame shared by Calendar, My Profile, and OneForm.
 * Content is produced by BosqueGrande renderers and is escaped at its source.
 *
 * @param array<string,string> $args
 */
function bgev_public_app_shell( string $content, array $args = [] ): string {
    $args = wp_parse_args( $args, [
        'context' => 'calendar',
        'main_id' => 'bg-public-app-main',
        'title' => '',
        'eyebrow' => '',
        'intro' => '',
        'skip_label' => 'Skip to content',
        'right_url' => '',
        'footer_url' => '',
        'footer_label' => '',
    ] );
    $context = sanitize_html_class( (string) $args['context'] );
    $mainId = sanitize_html_class( (string) $args['main_id'] );
    if ( in_array( $context, [ 'calendar', 'profile' ], true ) ) {
        $args['title'] = bg_str( 'shell_' . $context . '_title', (string) $args['title'] );
        $args['eyebrow'] = bg_str( 'shell_' . $context . '_eyebrow', (string) $args['eyebrow'] );
        $args['intro'] = bg_str( 'shell_' . $context . '_intro', (string) $args['intro'] );
    }
    if ( (string) $args['skip_label'] === 'Skip to content' ) {
        $args['skip_label'] = bg_str( 'shell_skip_content', 'Skip to content' );
    }

    ob_start();
    ?>
    <a class="bg-event-skip" href="#<?php echo esc_attr( $mainId ); ?>"><?php echo esc_html( (string) $args['skip_label'] ); ?></a>
    <div class="bg-event-app-frame bg-public-app-frame bg-public-app-frame--<?php echo esc_attr( $context ); ?>">
        <?php echo bgev_public_app_header( (string) $args['right_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <main id="<?php echo esc_attr( $mainId ); ?>" class="bg-event-app-main bg-public-app-main" tabindex="-1">
            <section class="bg-public-app-surface bg-public-app-surface--<?php echo esc_attr( $context ); ?>">
                <?php if ( (string) $args['title'] !== '' ) : ?>
                    <header class="bg-public-app-heading">
                        <?php if ( (string) $args['eyebrow'] !== '' ) : ?><p class="bg-public-app-eyebrow"><?php echo esc_html( (string) $args['eyebrow'] ); ?></p><?php endif; ?>
                        <h1><?php echo esc_html( (string) $args['title'] ); ?></h1>
                        <?php if ( (string) $args['intro'] !== '' ) : ?><p class="bg-public-app-intro"><?php echo esc_html( (string) $args['intro'] ); ?></p><?php endif; ?>
                    </header>
                <?php endif; ?>
                <div class="bg-public-app-content"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
            </section>
        </main>
        <?php echo bgev_public_app_footer( (string) $args['footer_url'], (string) $args['footer_label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Force ownership while a custom query-var route is emitting its document.
 * The getter/setter is intentionally tiny so companion plugins can share the
 * shell before WordPress reaches template_include.
 */
function bgev_force_public_document( ?bool $set = null ): bool {
    static $forced = false;
    if ( $set !== null ) $forced = $set;
    return $forced;
}

/**
 * Stable owned-document contract for custom public routes.
 *
 * Usage: bgev_output_owned_document($escapedOrRendererOwnedHtml, [
 *   'context' => 'donation', 'body_class' => 'my-route', 'title' => 'Give',
 *   'main_id' => 'my-main', 'skip_label' => 'Skip to giving',
 *   'no_cache' => true, 'after_content' => $fixedScripts,
 * ]); The caller remains responsible for exit when used in template_redirect.
 *
 * @param array<string,mixed> $args
 */
function bgev_output_owned_document( string $content, array $args = [] ): void {
    $args = wp_parse_args( $args, [
        'context' => 'site',
        'body_class' => '',
        'main_id' => 'bg-public-app-main',
        'title' => '',
        'eyebrow' => '',
        'intro' => '',
        'skip_label' => bg_str( 'shell_skip_content', 'Skip to content' ),
        'right_url' => '',
        'footer_url' => '',
        'footer_label' => '',
        'after_content' => '',
        'already_framed' => false,
        'no_cache' => true,
    ] );
    if ( ! empty( $args['no_cache'] ) ) {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        nocache_headers();
        if ( ! headers_sent() ) {
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
            header( 'Vary: Cookie', false );
        }
    }
    bgev_force_public_document( true );
    if ( ! headers_sent() ) {
        header( 'X-BosqueGrande-Shell-Build: ' . sanitize_text_field( (string) BG_VERSION ), true );
        header( 'X-BosqueGrande-Shell-Intent: owned', true );
    }
    $bodyClasses = preg_split( '/\s+/', trim( (string) $args['body_class'] ) ) ?: [];
    $bodyClasses = array_values( array_filter( array_map( 'sanitize_html_class', $bodyClasses ) ) );
    $framed = ! empty( $args['already_framed'] ) ? $content : bgev_public_app_shell( $content, $args );
    ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo( 'charset' ); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php if ( ! current_theme_supports( 'title-tag' ) ) : ?><title><?php echo esc_html( wp_get_document_title() ); ?></title><?php endif; ?>
        <meta name="bosquegrande-shell-template" content="route-<?php echo esc_attr( sanitize_key( (string) $args['context'] ) ); ?>">
        <?php wp_head(); ?>
    </head>
    <body <?php body_class( array_merge( [ 'bg-event-app-page', 'bg-public-app-page' ], $bodyClasses ) ); ?>>
    <?php wp_body_open(); ?>
    <?php echo $framed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller/renderers own escaping. ?>
    <?php echo (string) $args['after_content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller owns fixed scripts. ?>
    <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}

function bgev_render( $atts = [], $content = null, $shortcodeTag = '' ) {
    // If a migrated page still carries a calendar-only alias beside the new
    // complete [bosquegrande] app, omit the redundant calendar rather than
    // creating duplicate view controls and event-card landmarks.
    if ( (string) $shortcodeTag !== '' && bgev_page_has_complete_app() ) return '';
    $atts = shortcode_atts( [ 'view' => 'pinboard', 'per_page' => 12, 'scope' => 'upcoming' ], $atts, 'bg_calendar' );
    $filters = bgev_current_filters();
    $loc  = $filters['loc'];
    $int  = $filters['int'];
    $view = sanitize_text_field( $_GET['bg_view'] ?? $atts['view'] );
    $scope = sanitize_text_field( $_GET['bg_scope'] ?? $atts['scope'] );
    if ( ! in_array( $scope, [ 'upcoming', 'past', 'all' ], true ) ) $scope = 'upcoming';
    $pg   = max( 1, (int) ( $_GET['bg_pg'] ?? 1 ) );
    $mo   = (int) ( $_GET['bg_month'] ?? current_time( 'n' ) );
    $yr   = (int) ( $_GET['bg_year'] ?? current_time( 'Y' ) );
    $date = sanitize_text_field( $_GET['bg_date'] ?? '' );
    if ( $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) $date = '';
    if ( ! in_array( $view, [ 'pinboard', 'calendar', 'list' ] ) ) $view = 'pinboard';
    $base = bgev_current_base();
    $s    = get_option( BGEV_OPT, [] );

    $preserve = [ 'bgev_loc' => $loc, 'bgev_int' => $int, 'bgev_q' => trim( sanitize_text_field( wp_unslash( $_GET['bgev_q'] ?? '' ) ) ) ];
    if ( $date ) $preserve['bg_date'] = $date;
    if ( $scope !== 'upcoming' ) $preserve['bg_scope'] = $scope;

    ob_start();
    echo '<div class="bgc-cal"><nav class="bgc-view-sw" aria-label="' . esc_attr( bgev_wording( 'calendar_view_navigation_aria', 'Calendar view and date range' ) ) . '">';
    foreach ( [ 'pinboard' => bg_str( 'view_pinboard', 'Pinboard' ), 'calendar' => bg_str( 'view_calendar', 'Calendar' ), 'list' => bg_str( 'view_list', 'List' ) ] as $v => $l ) {
        // Preserve the browsed month only while remaining in Calendar, and
        // preserve a selected date only while remaining in List.
        $sw_args = bgev_calendar_state_args( [ 'bg_pg', 'bg_view', 'bg_scope', 'bg_date', 'bg_month', 'bg_year' ] );
        $sw_args['bg_view'] = $v;
        if ( $v === 'list' && $date ) $sw_args['bg_date'] = $date;
        if ( $scope !== 'upcoming' && $v !== 'calendar' ) $sw_args['bg_scope'] = $scope;
        if ( $v === 'calendar' && $view === 'calendar' ) {
            $sw_args['bg_month'] = $mo;
            $sw_args['bg_year'] = $yr;
        }
        echo '<a href="' . esc_url( add_query_arg( $sw_args, $base ) ) . '" class="bgc-view-btn' . ( $v === $view ? ' bgc-view-active' : '' ) . '"' . ( $v === $view ? ' aria-current="page"' : '' ) . '>' . esc_html( $l ) . '</a>';
    }
    // Upcoming/Past toggle. The month grid browses time natively,
    // so the toggle renders only on pinboard and list views.
    if ( $view !== 'calendar' ) {
        echo '<span class="bgc-scope-sw">';
        foreach ( [ 'upcoming' => bg_str( 'scope_upcoming', 'Upcoming' ), 'past' => bg_str( 'scope_past', 'Past' ) ] as $sc => $sl ) {
            $sc_args = bgev_calendar_state_args( [ 'bg_pg', 'bg_scope', 'bg_date', 'bg_month', 'bg_year' ] );
            $sc_args['bg_view'] = $view;
            // An explicit click must override even a non-default shortcode
            // attribute such as [bg_calendar scope="past"].
            $sc_args['bg_scope'] = $sc;
            $sc_url = add_query_arg( $sc_args, $base );
            $active = ( $scope === $sc || ( $sc === 'upcoming' && $scope === 'all' ) );
            echo '<a href="' . esc_url( $sc_url ) . '" class="bgc-view-btn bgc-scope-btn' . ( $active ? ' bgc-view-active' : '' ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $sl ) . '</a>';
        }
        echo '</span>';
    }
    if ( ! empty( $s['enable_submissions'] ) ) {
        $suggest_url = bgev_get_submission_url();
        if ( $suggest_url ) {
            echo '<a href="' . esc_url( $suggest_url ) . '" class="bgc-view-btn bgc-view-suggest">' . esc_html( bgev_wording( 'calendar_suggest_event', '+ Suggest Event' ) ) . '</a>';
        }
    }
    echo '</nav>';
    echo '<label class="bgc-open-pref">' . esc_html( bgev_wording( 'calendar_open_registration_label', 'Open registration in' ) ) . ' '
        . '<select class="bgc-open-select" aria-label="' . esc_attr( bgev_wording( 'calendar_open_registration_aria', 'Choose where event registration opens' ) ) . '">'
        . '<option value="same">' . esc_html( bgev_wording( 'calendar_open_same', 'Same window' ) ) . '</option>'
        . '<option value="tab">' . esc_html( bgev_wording( 'calendar_open_tab', 'New tab' ) ) . '</option>'
        . '<option value="window">' . esc_html( bgev_wording( 'calendar_open_window', 'New window' ) ) . '</option>'
        . '</select></label>';

    $search_state = bgev_search_state();
    if ( $search_state['active'] && ! $search_state['strict'] ) {
        echo '<p class="bgc-search-note">' . esc_html( bg_str( 'search_no_strict_note', 'No entries match every term; the nearest matches are shown.' ) ) . '</p>';
    }

    // Show "filtered to date" notice when active
    if ( $date && in_array( $view, [ 'pinboard', 'list' ], true ) ) {
        $clear_url = bgev_calendar_state_url( [], [ 'bg_date', 'bg_pg' ] );
        $human = date_i18n( 'l, F j, Y', strtotime( $date ) );
        echo '<div class="bgc-date-filter-bar">';
        echo '<span>' . bgev_wording_html( 'calendar_showing_date', 'Showing events on {date}', [ 'date' => '<strong>' . esc_html( $human ) . '</strong>' ] ) . '</span>';
        echo '<a href="' . esc_url( $clear_url ) . '" class="bgc-date-filter-clear">' . esc_html( bgev_wording( 'calendar_date_clear', '× Clear' ) ) . '</a>';
        echo '</div>';
    }

    if ( $view === 'calendar' ) {
        bgev_vcal( $mo, $yr, $loc, $int, $base );
    } else {
        $q = new WP_Query( bgev_qargs( [ 'pp' => $atts['per_page'], 'pg' => $pg, 'loc' => $loc, 'int' => $int, 'date' => $date, 'scope' => $scope ] ) );
        $view === 'list' ? bgev_vlist( $q, $scope ) : bgev_vpin( $q, $scope );
        if ( $q->max_num_pages > 1 && $pg < $q->max_num_pages ) {
            echo '<div class="bgc-pagination"><a href="' . esc_url( add_query_arg( array_merge( [ 'bg_pg' => $pg + 1, 'bg_view' => $view ], $preserve ), $base ) ) . '" class="bgc-more-btn">' . esc_html( bgev_wording( 'calendar_more_events', 'More Events' ) ) . '</a></div>';
        }
        wp_reset_postdata();
    }
    echo <<<'HTML'
<script>
(function(){
  var script=document.currentScript;
  var root=script&&script.closest('.bgc-cal');
  if(!root)return;
  var select=root.querySelector('.bgc-open-select');
  if(!select)return;
  var key='bg_registration_open_mode';
  var allowed=['same','tab','window'];
  try{
    var saved=localStorage.getItem(key);
    if(allowed.indexOf(saved)!==-1)select.value=saved;
  }catch(ignore){}
  select.addEventListener('change',function(){
    try{localStorage.setItem(key,select.value);}catch(ignore){}
  });
  root.addEventListener('click',function(event){
    var suggest=event.target.closest&&event.target.closest('a.bgc-view-suggest');
    if(suggest&&root.contains(suggest)&&suggest.getAttribute('href')==='#bgc-submit-form'){
      var form=document.getElementById('bgc-submit-form');
      if(form){
        event.preventDefault();
        form.focus({preventScroll:true});
        var reduce=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        form.scrollIntoView({behavior:reduce?'auto':'smooth',block:'start'});
      }
      return;
    }
    var link=event.target.closest&&event.target.closest('a.bgc-reg-btn');
    if(!link||!root.contains(link))return;
    if(select.value==='same'){
      link.removeAttribute('target');
      link.removeAttribute('rel');
      return;
    }
    if(select.value==='tab'){
      link.target='_blank';
      link.rel='noopener noreferrer';
      return;
    }
    event.preventDefault();
    var opened=window.open(link.href,'bg-registration-window','noopener,noreferrer,width=1100,height=820,resizable=yes,scrollbars=yes');
    if(!opened)window.location.assign(link.href);
  });
})();
</script>
HTML;
    echo '</div>';
    return ob_get_clean();
}

/**
 * The complete public application: search and filters, every calendar
 * view, and (when enabled) the suggestion form in one frame. The
 * calendar-only [bg_calendar] shortcode calls bgev_render() directly.
 */
function bgev_render_complete_app( $atts = [] ): string {
    static $documentRendered = false;
    if ( bgev_public_document_context() === 'calendar' ) {
        if ( $documentRendered ) return '<!-- BosqueGrande application already rendered. -->';
        $documentRendered = true;
    }

    $previous = bgev_composite_app_context();
    bgev_composite_app_context( true );
    try {
        $content = '<div class="bg-public-calendar-tools">' . bg_tag_filter() . '</div>';
        $content .= '<div class="bg-public-calendar-view">' . bgev_render( $atts ) . '</div>';

        $settings = get_option( BGEV_OPT, [] );
        if ( ! empty( $settings['enable_submissions'] ) && bgev_get_submission_url() !== '' ) {
            $content .= '<section class="bg-public-submission" aria-label="' . esc_attr( bgev_wording( 'calendar_suggestion_section_aria', 'Suggest an event' ) ) . '">'
                . bgev_submit_form()
                . '</section>';
        }
    } finally {
        bgev_composite_app_context( $previous );
    }

    if ( bgev_public_document_context() === 'calendar' ) return $content;

    return bgev_public_app_shell( $content, [
        'context' => 'calendar',
        'main_id' => 'bg-calendar-app-main',
        'title' => 'Community Events',
        'eyebrow' => '{org}',
        'intro' => 'Search by place or interest, move between Pinboard, Calendar, and List, register, or suggest a community event.',
        'skip_label' => bgev_wording( 'calendar_skip', 'Skip to the events calendar' ),
        'right_url' => bgev_current_base(),
    ] );
}

function bgev_vpin( WP_Query $q, $scope = 'upcoming' ) {
    if ( ! $q->have_posts() ) {
        echo '<p class="bgc-none">' . esc_html( $scope === 'past' ? bg_str( 'none_past' ) : bg_str( 'none_upcoming' ) ) . '</p>';
        return;
    }
    $btn = bg_str( 'register_button', 'REGISTER' );
    echo '<div class="bgc-pinboard">';
    while ( $q->have_posts() ) {
        $q->the_post();
        $id    = get_the_ID();
        $lnk   = bgev_add_calendar_return( get_permalink( $id ), bgev_current_calendar_url() );
        $img   = bgev_img( $id );
        $wa    = bgev_registration_link( $id );
        $title = get_the_title();
        $dt    = bgev_date( $id );
        $tm    = bgev_time_range( $id );
        echo '<article class="bgc-card">';
        echo '<a href="' . esc_url( $lnk ) . '" class="bgc-card-img-link" aria-label="' . esc_attr( bgev_wording( 'calendar_card_details', 'View details for {event}', [ 'event' => $title ] ) ) . '">';
        echo $img
            ? '<div class="bgc-card-img"><img src="' . esc_url( $img ) . '" alt="' . esc_attr( $title ) . '" loading="lazy"></div>'
            : '<div class="bgc-card-img bgc-card-placeholder"></div>';
        echo '</a><div class="bgc-card-body">';
        if ( $dt ) echo '<p class="bgc-card-date">' . esc_html( $dt ) . '</p>';
        echo '<h3 class="bgc-card-title"><a href="' . esc_url( $lnk ) . '">' . esc_html( $title ) . '</a></h3>';
        $meta_parts = [];
        if ( $tm ) $meta_parts[] = $tm;
        if ( $meta_parts ) echo '<p class="bgc-card-meta">' . esc_html( implode( ' · ', $meta_parts ) ) . '</p>';
        if ( $wa && ! bgev_event_is_past( $id ) ) echo '<a href="' . esc_url( $wa ) . '" class="bgc-reg-btn">' . esc_html( $btn ) . '</a>';
        echo '</div></article>';
    }
    echo '</div>';
}

function bgev_vlist( WP_Query $q, $scope = 'upcoming' ) {
    if ( ! $q->have_posts() ) {
        echo '<p class="bgc-none">' . esc_html( $scope === 'past' ? bg_str( 'none_past' ) : bg_str( 'none_upcoming' ) ) . '</p>';
        return;
    }
    $btn = bg_str( 'register_button', 'REGISTER' );
    echo '<div class="bgc-list">';
    while ( $q->have_posts() ) {
        $q->the_post();
        $id    = get_the_ID();
        $lnk   = bgev_add_calendar_return( get_permalink( $id ), bgev_current_calendar_url() );
        $img   = bgev_img( $id );
        $wa    = bgev_registration_link( $id );
        $title = get_the_title();
        echo '<article class="bgc-list-item">';
        if ( $img ) echo '<a href="' . esc_url( $lnk ) . '" class="bgc-list-img"><img src="' . esc_url( $img ) . '" alt="' . esc_attr( $title ) . '" loading="lazy"></a>';
        echo '<div class="bgc-list-body">';
        echo '<h3 class="bgc-card-title"><a href="' . esc_url( $lnk ) . '">' . esc_html( $title ) . '</a></h3>';
        $dt = bgev_date( $id ); $tm = bgev_time_range( $id );
        $meta_parts = [];
        if ( $dt ) $meta_parts[] = $dt;
        if ( $tm ) $meta_parts[] = $tm;
        if ( $meta_parts ) echo '<p class="bgc-card-meta">' . esc_html( implode( ' · ', $meta_parts ) ) . '</p>';
        if ( $wa && ! bgev_event_is_past( $id ) ) echo '<a href="' . esc_url( $wa ) . '" class="bgc-reg-btn">' . esc_html( $btn ) . '</a>';
        echo '</div></article>';
    }
    echo '</div>';
}

function bgev_vcal( $mo, $yr, $loc, $int, $base ) {
    if ( $mo < 1 || $mo > 12 ) $mo = (int) current_time( 'n' );
    $current_year = (int) current_time( 'Y' );
    if ( $yr < 2020 || $yr > $current_year + 20 ) $yr = $current_year;
    $pm = $mo - 1; $py = $yr; if ( $pm < 1 ) { $pm = 12; $py--; }
    $nm = $mo + 1; $ny = $yr; if ( $nm > 12 ) { $nm = 1; $ny++; }

    $timezone  = wp_timezone();
    $first     = new DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $yr, $mo ), $timezone );
    $first_dow = (int) $first->format( 'w' );
    $dim       = cal_days_in_month( CAL_GREGORIAN, $mo, $yr );
    $last      = $first->setDate( $yr, $mo, $dim );
    $last_dow  = (int) $last->format( 'w' );

    // Window: Sunday before/equal to 1st through Saturday after/equal to last
    $window_start = $first->modify( "-$first_dow days" );
    $days_to_sat     = 6 - $last_dow;
    $window_end      = $last->modify( "+$days_to_sat days" );

    // Query everything in the visible window
    $qa = bgev_qargs( [ 'loc' => $loc, 'int' => $int ] );
    $qa['posts_per_page'] = -1;
    $qa['meta_query'] = [ [
        'key'     => '_bg_start_date',
        'value'   => [ $window_start->format( 'Y-m-d' ), $window_end->format( 'Y-m-d' ) ],
        'compare' => 'BETWEEN',
        'type'    => 'DATE',
    ] ];
    $q = new WP_Query( $qa );

    // Build events-by-date map
    $events_by_date = [];
    while ( $q->have_posts() ) {
        $q->the_post();
        $d = get_post_meta( get_the_ID(), '_bg_start_date', true );
        if ( $d ) {
            $event_id = get_the_ID();
            $events_by_date[ $d ][] = [
                't' => get_the_title(),
                'l' => bgev_add_calendar_return( get_permalink( $event_id ), bgev_current_calendar_url() ),
                'tm' => bgev_time_range( $event_id ),
            ];
        }
    }
    wp_reset_postdata();

    $today = current_time( 'Y-m-d' );
    $preserve = [ 'bgev_loc' => $loc, 'bgev_int' => $int, 'bgev_q' => trim( sanitize_text_field( wp_unslash( $_GET['bgev_q'] ?? '' ) ) ) ];
    $pc = [ '#C98A1B','#E53935','#FF6D00','#2DB02D','#AA00FF','#3700B3','#1F5130' ];

    // Header
    echo '<div class="bgc-cal-wrap"><div class="bgc-cal-hdr">';
    echo '<a href="' . esc_url( add_query_arg( array_merge( [ 'bg_view' => 'calendar', 'bg_month' => $pm, 'bg_year' => $py ], $preserve ), $base ) ) . '" class="bgc-cal-nav" aria-label="' . esc_attr( bgev_wording( 'calendar_previous_month', 'Previous month' ) ) . '">&laquo;</a>';
    echo '<h2 class="bgc-cal-mo">' . esc_html( wp_date( 'F Y', $first->getTimestamp(), $timezone ) ) . '</h2>';
    echo '<a href="' . esc_url( add_query_arg( array_merge( [ 'bg_view' => 'calendar', 'bg_month' => $nm, 'bg_year' => $ny ], $preserve ), $base ) ) . '" class="bgc-cal-nav" aria-label="' . esc_attr( bgev_wording( 'calendar_next_month', 'Next month' ) ) . '">&raquo;</a>';
    echo '</div><div class="bgc-cal-grid">';
    $weekday = new DateTimeImmutable( '2024-01-07 12:00:00', $timezone );
    for ( $weekdayIndex = 0; $weekdayIndex < 7; $weekdayIndex++ ) {
        echo '<div class="bgc-cal-dow">' . esc_html( wp_date( 'D', $weekday->modify( "+{$weekdayIndex} days" )->getTimestamp(), $timezone ) ) . '</div>';
    }

    // Iterate every day in the window (including other-month padding days)
    $cur = $window_start;
    while ( $cur <= $window_end ) {
        $cur_date = $cur->format( 'Y-m-d' );
        $cur_d    = (int) $cur->format( 'j' );
        $cur_m    = (int) $cur->format( 'n' );
        $is_other = ( $cur_m !== $mo );
        $is_today = ( $cur_date === $today );
        $is_past  = ( $cur_date < $today );

        $cls = 'bgc-cal-cell';
        if ( $is_other ) $cls .= ' bgc-cal-other';
        if ( $is_today ) $cls .= ' bgc-cal-today';
        if ( $is_past && ! $is_today ) $cls .= ' bgc-cal-past';
        if ( isset( $events_by_date[ $cur_date ] ) ) $cls .= ' bgc-cal-has';

        echo '<div class="' . $cls . '">';
        // data-dow feeds the phone agenda's weekday label (CSS attr());
        // the desktop grid ignores it.
        echo '<span class="bgc-cal-day" data-dow="' . esc_attr( wp_date( 'D', $cur->getTimestamp(), $timezone ) ) . '" aria-label="' . esc_attr( wp_date( 'l, F j, Y', $cur->getTimestamp(), $timezone ) ) . '">' . $cur_d . '</span>';

        if ( isset( $events_by_date[ $cur_date ] ) ) {
            $events = $events_by_date[ $cur_date ];
            $shown = array_slice( $events, 0, 3 );
            $remaining = count( $events ) - count( $shown );
            $ci = 0;
            echo '<div class="bgc-cal-events">';
            foreach ( $shown as $ev ) {
                $color = $pc[ $ci++ % 7 ];
                $pill_title = $ev['tm'] ? $ev['t'] . ' · ' . $ev['tm'] : $ev['t'];
                echo '<a href="' . esc_url( $ev['l'] ) . '" class="bgc-cal-pill" style="--pill-c:' . esc_attr( $color ) . '" title="' . esc_attr( $pill_title ) . '"><span class="bgc-cal-dot"></span><span class="bgc-cal-pill-copy"><span class="bgc-cal-pill-t">' . esc_html( wp_trim_words( $ev['t'], 10, '…' ) ) . '</span>' . ( $ev['tm'] ? '<span class="bgc-cal-pill-time">' . esc_html( $ev['tm'] ) . '</span>' : '' ) . '</span></a>';
            }
            if ( $remaining > 0 ) {
                $more_url = add_query_arg( array_merge( [ 'bg_view' => 'list', 'bg_date' => $cur_date ], $preserve ), $base );
                echo '<a href="' . esc_url( $more_url ) . '" class="bgc-cal-more">' . esc_html( bgev_wording( 'calendar_more_count', '+{count} more', [ 'count' => (string) $remaining ] ) ) . '</a>';
            }
            echo '</div>';
        }
        echo '</div>';

        $cur = $cur->modify( '+1 day' );
    }
    echo '</div></div>';
}

/**
 * Distinct gatherings per tag, for the filter dropdowns. A recurring
 * event (the same gathering on many dates) is counted once, not once
 * per occurrence: counting is by distinct normalized title per tag.
 */
function bg_tag_counts() {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT tt.taxonomy, t.slug, p.post_title
         FROM {$wpdb->posts} p
         JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
         JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
         WHERE p.post_type = 'bg_event' AND p.post_status = 'publish'
           AND tt.taxonomy IN ('bg_tag', %s)",
        bg_location_taxonomy()
    ) ) ?: [];
    return bgev_distinct_title_counts( $rows );
}

/** @param array<int, object{slug: string, post_title: string}> $rows */
function bgev_distinct_title_counts( $rows ) {
    $titles_by_slug = [];
    foreach ( $rows as $r ) {
        $norm = strtolower( trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^a-z0-9 ]+/i', ' ', (string) $r->post_title ) ) ) );
        if ( $norm === '' ) continue;
        $titles_by_slug[ $r->slug ][ $norm ] = true;
    }
    $counts = [];
    foreach ( $titles_by_slug as $slug => $titles ) $counts[ $slug ] = count( $titles );
    return $counts;
}

function bg_tag_filter( $atts = [], $content = null, $shortcodeTag = '' ) {
    // A migrated page may still contain [bg_filter] beside [bosquegrande].
    // The complete app already includes one filter bar, so suppress only the
    // redundant shortcode invocation; direct function composition is kept.
    if ( (string) $shortcodeTag !== '' && bgev_page_has_complete_app() ) return '';
    $filters = bgev_current_filters();
    $cur_loc = $filters['loc'];
    $cur_int = $filters['int'];
    $base = bgev_current_base();
    $counts = bg_tag_counts();

    $cur_q = trim( sanitize_text_field( wp_unslash( $_GET['bgev_q'] ?? '' ) ) );
    $hiddenState = bgev_calendar_state_args( [ 'bgev_q', 'bg_pg' ] );
    ob_start(); ?>
    <div class="bgc-filter-bar">
        <div class="bgc-filter-group bgc-search-group">
            <label class="bgc-filter-label" for="bgc-q"><?php echo esc_html( bg_str( 'search_label', 'Search:' ) ); ?></label>
            <form method="get" action="<?php echo esc_url( $base ); ?>" class="bgc-search-form">
                <?php foreach ( $hiddenState as $k => $v ) : ?><input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>"><?php endforeach; ?>
                <input type="search" id="bgc-q" name="bgev_q" class="bgc-search-input"
                    value="<?php echo esc_attr( $cur_q ); ?>"
                    placeholder="<?php echo esc_attr( bg_str( 'search_placeholder', 'Search events ("game night" -online)' ) ); ?>">
                <button type="submit" class="bgc-view-btn bgc-search-btn"><?php echo esc_html( bg_str( 'search_button', 'Search' ) ); ?></button>
                <?php if ( $cur_q !== '' ) : ?>
                    <a class="bgc-search-clear" href="<?php echo esc_url( bgev_calendar_state_url( [], [ 'bgev_q', 'bg_pg' ] ) ); ?>"><?php echo esc_html( bg_str( 'search_clear', 'Clear' ) ); ?></a>
                <?php endif; ?>
            </form>
        </div>
        <div class="bgc-filter-group">
            <label class="bgc-filter-label" for="bgc-location-filter"><?php echo esc_html( bgev_wording( 'filter_location_label', 'Location:' ) ); ?></label>
            <select id="bgc-location-filter" class="bgc-filter-select" data-axis="loc" aria-label="<?php echo esc_attr( bgev_wording( 'filter_location_aria', 'Filter events by location' ) ); ?>" onchange="bgcFilter('loc', this.value)">
                <option value=""><?php echo esc_html( bgev_wording( 'filter_all_locations', 'All Locations' ) ); ?></option>
                <?php foreach ( bgev_location_tags() as $slug => $name ) :
                    $cnt = $counts[ $slug ] ?? 0; ?>
                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $cur_loc, $slug ); ?>><?php echo esc_html( bgev_public_tag_label( 'location', $slug, $name ) ); ?><?php echo $cnt ? ' (' . (int) $cnt . ')' : ''; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bgc-filter-group">
            <label class="bgc-filter-label" for="bgc-interest-filter"><?php echo esc_html( bgev_wording( 'filter_interest_label', 'Interest:' ) ); ?></label>
            <select id="bgc-interest-filter" class="bgc-filter-select" data-axis="int" aria-label="<?php echo esc_attr( bgev_wording( 'filter_interest_aria', 'Filter events by interest' ) ); ?>" onchange="bgcFilter('int', this.value)">
                <option value=""><?php echo esc_html( bgev_wording( 'filter_all_interests', 'All Interests' ) ); ?></option>
                <?php foreach ( bgev_interest_tags() as $slug => $name ) :
                    $cnt = $counts[ $slug ] ?? 0; ?>
                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $cur_int, $slug ); ?>><?php echo esc_html( bgev_public_tag_label( 'interest', $slug, $name ) ); ?><?php echo $cnt ? ' (' . (int) $cnt . ')' : ''; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ( $cur_loc || $cur_int ) : ?>
            <a href="<?php echo esc_url( bgev_calendar_state_url( [], [ 'bgev_loc', 'bgev_int', 'bg_pg' ] ) ); ?>" class="bgc-filter-clear"><?php echo esc_html( bgev_wording( 'filter_clear_all', '× Clear all' ) ); ?></a>
        <?php endif; ?>
    </div>
    <script>
    /* Preserve the other axis when switching one dropdown. */
    if (typeof window.bgcFilter !== 'function') {
        window.bgcFilter = function(axis, val) {
            /* Start from the server's canonical state, then change one axis. */
            var url = new URL(<?php echo wp_json_encode( bgev_calendar_state_url( [], [ 'bg_pg' ] ) ); ?>, window.location.href);
            url.searchParams.delete('bg_pg');
            
            if (val) url.searchParams.set('bgev_' + axis, val);
            else url.searchParams.delete('bgev_' + axis);
            window.location = url.toString();
        };
    }
    </script>
    <?php return ob_get_clean();
}

function bgev_submit_form( $atts = [], $content = null, $shortcodeTag = '' ) {
    // See bg_tag_filter(): prevent duplicate form IDs on an old three-shortcode
    // page without changing the standalone [bg_submit] compatibility contract.
    if ( (string) $shortcodeTag !== '' && bgev_page_has_complete_app() ) return '';
    $s = get_option( BGEV_OPT, [] );
    if ( empty( $s['enable_submissions'] ) ) {
        return '<p>' . esc_html( bgev_wording( 'suggest_disabled_form', 'Event submissions are not currently enabled.' ) ) . '</p>';
    }
    $contact = $s['designated_contact_email'] ?? '';
    $nonce   = wp_create_nonce( 'wp_rest' );
    $endpoint = esc_url_raw( rest_url( 'gathering-tree/v1/submit' ) );
    $now_ts  = time();
    $required = bgev_wording( 'suggest_required_marker', '*' );
    $copy = [
        'submitting' => bgev_wording( 'suggest_js_submitting', 'Submitting…' ),
        'checkEmail' => bgev_wording( 'suggest_js_check_email', 'Check your email.' ),
        'verifyFallback' => bgev_wording(
            'suggest_js_verify_fallback',
            "We've sent a verification link. Click it within {hours} hours to confirm your suggestion.",
            [ 'hours' => (string) BGEV_SUGGESTION_VERIFY_HOURS ]
        ),
        'failed' => bgev_wording( 'suggest_js_failed', 'Submission failed. Please try again.' ),
        'network' => bgev_wording( 'suggest_js_network', 'Network error. Please try again.' ),
    ];

    ob_start(); ?>
    <div class="bgc-submit-wrap" id="bgc-submit-wrap">
        <div class="bgc-submit-intro">
            <h2><?php echo esc_html( bgev_wording( 'suggest_heading', 'Suggest a Community Event' ) ); ?></h2>
            <p><?php echo esc_html( bgev_wording( 'suggest_intro', "Have a community event you'd like added to the calendar? Fill out the form below & we'll send a verification link to your email, and once you confirm, your suggestion goes into review." ) ); ?></p>
            <?php if ( $contact ) : ?>
            <p class="bgc-submit-note"><strong><?php echo esc_html( bgev_wording( 'suggest_image_heading', '📷 Have an image to suggest?' ) ); ?></strong>
                <?php echo esc_html( bgev_wording( 'suggest_image_before_email', 'Email it directly to' ) ); ?>
                <a href="mailto:<?php echo esc_attr( $contact ); ?>"><?php echo esc_html( $contact ); ?></a><?php echo esc_html( bgev_wording( 'suggest_image_after_email', "; image uploads aren't accepted through this form for security reasons." ) ); ?></p>
            <?php endif; ?>
        </div>

        <form id="bgc-submit-form" class="bgc-submit-form" method="post" action="<?php echo esc_url( $endpoint ); ?>" accept-charset="UTF-8" tabindex="-1">
            <div class="bgc-form-row">
                <label for="bgc-name"><?php echo esc_html( bgev_wording( 'suggest_name_label', 'Your name' ) ); ?> <span class="bgc-req"><?php echo esc_html( $required ); ?></span></label>
                <input type="text" id="bgc-name" name="name" autocomplete="name" required maxlength="100">
            </div>
            <div class="bgc-form-row">
                <label for="bgc-email"><?php echo esc_html( bgev_wording( 'suggest_email_label', 'Your email' ) ); ?> <span class="bgc-req"><?php echo esc_html( $required ); ?></span></label>
                <input type="email" id="bgc-email" name="email" autocomplete="email" required maxlength="200">
                <small><?php echo esc_html( bgev_wording( 'suggest_email_help', "We'll send a verification link here. Used only for follow-up about this submission." ) ); ?></small>
            </div>

            <hr>

            <div class="bgc-form-row">
                <label for="bgc-title"><?php echo esc_html( bgev_wording( 'suggest_title_label', 'Event title' ) ); ?> <span class="bgc-req"><?php echo esc_html( $required ); ?></span></label>
                <input type="text" id="bgc-title" name="title" required maxlength="200">
            </div>
            <div class="bgc-form-grid">
                <div class="bgc-form-row">
                    <label for="bgc-start-date"><?php echo esc_html( bgev_wording( 'suggest_start_date_label', 'Start date' ) ); ?> <span class="bgc-req"><?php echo esc_html( $required ); ?></span></label>
                    <input type="date" id="bgc-start-date" name="start_date" required>
                </div>
                <div class="bgc-form-row">
                    <label for="bgc-start-time"><?php echo esc_html( bgev_wording( 'suggest_start_time_label', 'Start time' ) ); ?> <span class="bgc-req"><?php echo esc_html( $required ); ?></span></label>
                    <input type="time" id="bgc-start-time" name="start_time" required>
                </div>
                <div class="bgc-form-row">
                    <label for="bgc-end-date"><?php echo esc_html( bgev_wording( 'suggest_end_date_label', 'End date' ) ); ?></label>
                    <input type="date" id="bgc-end-date" name="end_date">
                </div>
                <div class="bgc-form-row">
                    <label for="bgc-end-time"><?php echo esc_html( bgev_wording( 'suggest_end_time_label', 'End time' ) ); ?></label>
                    <input type="time" id="bgc-end-time" name="end_time">
                </div>
            </div>
            <div class="bgc-form-row">
                <label for="bgc-location"><?php echo esc_html( bgev_wording( 'suggest_location_label', 'Location' ) ); ?> <span class="bgc-req"><?php echo esc_html( $required ); ?></span></label>
                <input type="text" id="bgc-location" name="location" required maxlength="200" placeholder="<?php echo esc_attr( bgev_wording( 'suggest_location_placeholder', 'Venue name, city' ) ); ?>">
            </div>
            <div class="bgc-form-row">
                <label for="bgc-reg-url"><?php echo esc_html( bgev_wording( 'suggest_registration_url_label', 'Registration URL' ) ); ?></label>
                <input type="url" id="bgc-reg-url" name="reg_url" maxlength="500" placeholder="<?php echo esc_attr( bgev_wording( 'suggest_registration_url_placeholder', 'https://...' ) ); ?>">
                <small><?php echo esc_html( bgev_wording( 'suggest_registration_url_help', 'Optional. Where attendees can RSVP or buy tickets.' ) ); ?></small>
            </div>
            <div class="bgc-form-row">
                <label for="bgc-description"><?php echo esc_html( bgev_wording( 'suggest_description_label', 'Description' ) ); ?> <span class="bgc-req"><?php echo esc_html( $required ); ?></span></label>
                <textarea id="bgc-description" name="description" rows="5" required maxlength="3000"></textarea>
                <small><?php echo esc_html( bgev_wording( 'suggest_description_help', "What's the event about? Plain text or basic formatting (paragraphs, bold, italic, links, lists). Images not allowed; email them to the contact above." ) ); ?></small>
            </div>
            <div class="bgc-form-grid">
                <div class="bgc-form-row">
                    <label for="bgc-loc-tag"><?php echo esc_html( bgev_wording( 'suggest_location_tag_label', 'Suggested location' ) ); ?></label>
                    <select id="bgc-loc-tag" name="loc_tag">
                        <option value=""><?php echo esc_html( bgev_wording( 'suggest_optional', '(Optional)' ) ); ?></option>
                        <?php foreach ( bgev_location_tags() as $slug => $name ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( bgev_public_tag_label( 'location', $slug, $name ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bgc-form-row">
                    <label for="bgc-int-tag"><?php echo esc_html( bgev_wording( 'suggest_interest_tag_label', 'Suggested interest' ) ); ?></label>
                    <select id="bgc-int-tag" name="int_tag">
                        <option value=""><?php echo esc_html( bgev_wording( 'suggest_optional', '(Optional)' ) ); ?></option>
                        <?php foreach ( bgev_interest_tags() as $slug => $name ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( bgev_public_tag_label( 'interest', $slug, $name ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Honeypot: bots fill this -->
            <div class="bgc-hp" aria-hidden="true" style="position:absolute;left:-9999px;height:0;overflow:hidden">
                <label for="bgc-website"><?php echo esc_html( bgev_wording( 'suggest_honeypot_label', 'Website (leave blank)' ) ); ?></label>
                <input type="text" id="bgc-website" name="bgev_hp" tabindex="-1" autocomplete="off">
            </div>
            <input type="hidden" name="bgev_ts" value="<?php echo (int) $now_ts; ?>">

            <p><button type="submit" class="bgc-reg-btn bgc-reg-lg"><?php echo esc_html( bgev_wording( 'suggest_submit_button', 'Submit Suggestion' ) ); ?></button></p>

            <div id="bgc-submit-result" class="bgc-submit-result" role="status" aria-live="polite"></div>
        </form>
    </div>

    <script>
    (function(){
        var form=document.getElementById('bgc-submit-form');
        var result=document.getElementById('bgc-submit-result');
        var nonce=<?php echo wp_json_encode( $nonce ); ?>;
        var endpoint=<?php echo wp_json_encode( $endpoint ); ?>;
        var copy=<?php echo wp_json_encode( $copy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
        form.addEventListener('submit',function(e){
            e.preventDefault();
            result.className='bgc-submit-result bgc-submit-pending';
            result.textContent=copy.submitting;
            var btn=form.querySelector('button[type=submit]');
            btn.disabled=true;
            var fd=new FormData(form);
            fetch(endpoint,{method:'POST',body:fd,headers:{'X-WP-Nonce':nonce}})
                .then(function(r){return r.json();})
                .then(function(d){
                    if(d.success){
                        result.className='bgc-submit-result bgc-submit-success';
                        result.textContent=copy.checkEmail+' '+(d.message||copy.verifyFallback);
                        form.reset();
                    } else {
                        result.className='bgc-submit-result bgc-submit-error';
                        result.textContent=d.message||copy.failed;
                        btn.disabled=false;
                    }
                })
                .catch(function(){
                    result.className='bgc-submit-result bgc-submit-error';
                    result.textContent=copy.network;
                    btn.disabled=false;
                });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

function bgev_scrub_legacy_links( $content ) {
    if ( ! is_singular( 'bg_event' ) ) return $content;
    $domains = class_exists( '\\BosqueGrande\\Modules\\Auditor\\AuditorModule' )
        ? \BosqueGrande\Modules\Auditor\AuditorModule::scrubDomains()
        : array();
    foreach ( $domains as $d ) {
        $q = preg_quote( $d, '/' );
        $content = preg_replace( '/<a\b[^>]*href=["\'][^"\']*' . $q . '[^"\']*["\'][^>]*>(.*?)<\/a>/is', '$1', $content );
    }
    return $content;
}

function bgev_meta_box( $post ) {
    wp_nonce_field( 'bgev_mb', 'bgev_mb_n' );
    wp_enqueue_media();

    $f = [
        '_bg_src_key', '_bg_end_date', '_bg_end_time', '_bg_image',
        '_bg_image_manual', '_bg_image_synced', '_bg_reg_url', '_bg_start_date',
        '_bg_start_time', '_bg_location_text',
        '_bg_submitter_name', '_bg_submitter_email'
    ];
    $m = []; foreach ( $f as $k ) $m[ $k ] = get_post_meta( $post->ID, $k, true );
    $encryption = \BosqueGrande\Core\Plugin::instance()->container()->get( 'encryption' );
    foreach ( [ '_bg_submitter_name', '_bg_submitter_email' ] as $sensitive_key ) {
        $m[ $sensitive_key ] = $encryption->decrypt(
            (string) $m[ $sensitive_key ],
            'event-meta:' . (int) $post->ID . ':' . $sensitive_key
        );
    }

    $is_pending = ( $post->post_status === 'pending' );
    $photos = get_post_meta( $post->ID, '_bg_photos', true );
    $photos = is_array( $photos ) ? $photos : [ 'primary' => 0, 'secondary' => [], 'override' => 0 ];
    $primary = (int) ( $photos['primary'] ?? 0 );
    if ( ! $primary ) $primary = (int) get_post_thumbnail_id( $post->ID );
    $secondary = array_pad( array_map( 'intval', (array) ( $photos['secondary'] ?? [] ) ), 2, 0 );
    $photo_url = $primary ? (string) wp_get_attachment_image_url( $primary, 'large' ) : (string) $m['_bg_image'];
    $current_slug = $post->post_name ?: sanitize_title( (string) $post->post_title );
    $display_url = $post->post_status === 'publish'
        ? get_permalink( $post )
        : ( get_preview_post_link( $post ) ?: get_permalink( $post ) );
    $end_input = bgev_time_input_value( $m['_bg_end_time'] ?: bgev_end_time( $post->ID ) );
    wp_nonce_field( 'bg_photos', 'bg_photos_nonce' );
    ?>
    <div class="bgev-ed-event-editor">
        <div class="bgev-ed-editor-intro">
            <div>
                <strong>Event Editor</strong>
                <span>Create the event here. You do not need the WordPress block editor.</span>
            </div>
            <span class="bgev-ed-source">Source: <?php echo esc_html( $m['_bg_src_key'] ?: 'Entered directly' ); ?></span>
        </div>

        <section class="bgev-ed-editor-section">
            <label class="bgev-ed-field bgev-ed-field-title" for="bgev-ed-title">
                <span>Event title</span>
                <input type="text" id="bgev-ed-title" name="bgev_ed_title" value="<?php echo esc_attr( $post->post_title ); ?>" class="widefat" autocomplete="off" required>
            </label>

            <div class="bgev-ed-field bgev-ed-description-field">
                <div class="bgev-ed-field-heading">
                    <span>Event description</span>
                    <small>Use <strong>Visual</strong> for WYSIWYG editing or <strong>Text</strong> to edit the HTML directly. The Add Media button inserts photos into the description.</small>
                </div>
                <?php
                wp_editor( (string) $post->post_content, 'bgev_ed_event_content', [
                    'textarea_name' => 'bgev_ed_content',
                    'media_buttons' => true,
                    'textarea_rows' => 14,
                    'teeny'         => false,
                    'tinymce'       => true,
                    'quicktags'     => true,
                ] );
                ?>
            </div>
        </section>

        <section class="bgev-ed-editor-section">
            <h3>When and where</h3>
            <div class="bgev-ed-grid bgev-ed-grid-4">
                <label class="bgev-ed-field"><span>Start date</span><input type="date" name="bgev_sd" value="<?php echo esc_attr( $m['_bg_start_date'] ); ?>"></label>
                <label class="bgev-ed-field"><span>Start time</span><input type="time" id="bgev-ed-start-time" name="bgev_st" value="<?php echo esc_attr( bgev_time_input_value( $m['_bg_start_time'] ) ); ?>"></label>
                <label class="bgev-ed-field"><span>End date</span><input type="date" id="bgev-ed-end-date" name="bgev_ed" value="<?php echo esc_attr( $m['_bg_end_date'] ); ?>"<?php echo $m['_bg_start_date'] !== '' ? ' min="' . esc_attr( $m['_bg_start_date'] ) . '"' : ''; ?>><small>Leave blank for the same day.</small></label>
                <label class="bgev-ed-field"><span>End time</span><input type="time" id="bgev-ed-end-time" name="bgev_et" value="<?php echo esc_attr( $end_input ); ?>"><small>If omitted, BosqueGrande assumes one hour after the start.</small></label>
            </div>
            <label class="bgev-ed-field" for="bgev-ed-location">
                <span>Location</span>
                <input type="text" id="bgev-ed-location" name="bgev_location_text" value="<?php echo esc_attr( $m['_bg_location_text'] ); ?>" class="widefat" placeholder="Venue, town, state, or online location">
            </label>
        </section>

        <section class="bgev-ed-editor-section bgev-ed-photo-section">
            <h3>Event photo</h3>
            <div class="bgev-ed-photo-layout">
                <div id="bgev-ed-photo-preview" class="bgev-ed-photo-preview">
                    <?php if ( $photo_url ) : ?><img src="<?php echo esc_url( $photo_url ); ?>" alt=""><?php else : ?><span>No photo selected</span><?php endif; ?>
                </div>
                <div class="bgev-ed-photo-actions">
                    <p>Choose or upload the main event photo here. You can also place additional photos inside the event description with <strong>Add Media</strong>.</p>
                    <button type="button" id="bgev-ed-photo-pick" class="button button-secondary">Choose / Upload Photo</button>
                    <button type="button" id="bgev-ed-photo-clear" class="button-link-delete">Remove Photo</button>
                </div>
            </div>
            <input type="hidden" id="bgev-ed-photo-primary" name="bg_photo_primary" value="<?php echo (int) $primary; ?>">
            <input type="hidden" name="bg_photo_sec1" value="<?php echo (int) $secondary[0]; ?>">
            <input type="hidden" name="bg_photo_sec2" value="<?php echo (int) $secondary[1]; ?>">
            <input type="hidden" name="bg_photo_override" value="<?php echo ! empty( $photos['override'] ) ? '1' : '0'; ?>">
            <input type="hidden" id="bgev_img_url" name="bgev_img" value="<?php echo esc_url( $m['_bg_image'] ); ?>">
        </section>

        <section class="bgev-ed-editor-section bgev-ed-url-section">
            <h3>Event URL</h3>
            <p class="description">Set a readable web address, then use Display URL to open the event exactly as a visitor will see it.</p>
            <div class="bgev-ed-url-row">
                <label class="bgev-ed-field bgev-ed-url-slug" for="bgev-ed-slug"><span>URL ending</span><input type="text" id="bgev-ed-slug" name="bgev_ed_slug" value="<?php echo esc_attr( $current_slug ); ?>" placeholder="event-name"></label>
                <button type="button" id="bgev-ed-set-url" class="button button-secondary">Set URL</button>
                <button type="button" id="bgev-ed-display-url" class="button button-secondary">Display URL</button>
            </div>
            <input type="text" id="bgev-ed-url-display" class="widefat bgev-ed-url-display" value="<?php echo esc_attr( $display_url ); ?>" readonly aria-label="Current event URL">
            <p id="bgev-ed-url-status" class="description" aria-live="polite"></p>
        </section>

        <section class="bgev-ed-editor-section">
            <h3>Registration</h3>
            <?php if ( ! apply_filters( 'bg_native_registration', true ) ) : ?>
                <label class="bgev-ed-field"><span>Registration URL</span><input type="url" name="bgev_reg" value="<?php echo esc_url( $m['_bg_reg_url'] ); ?>" class="widefat"></label>
            <?php else : ?>
                <p class="bgev-ed-native-registration"><strong>Registration:</strong> automatic through BosqueGrande's built-in form.</p>
                <?php
                $all_tiers = \BosqueGrande\Modules\Registration\Tiers::all();
                $chosen_tiers = get_post_meta( $post->ID, \BosqueGrande\Modules\Registration\Tiers::EVENT_META, true );
                $chosen_tiers = is_array( $chosen_tiers ) ? array_map( 'strval', $chosen_tiers ) : [];
                if ( count( $all_tiers ) > 1 ) : ?>
                <fieldset class="bgev-ed-field"><legend>Options offered for this event</legend>
                    <input type="hidden" name="bgev_tiers_present" value="1">
                    <?php foreach ( $all_tiers as $tier_key => $tier ) : ?>
                        <label style="display:block"><input type="checkbox" name="bgev_tiers[]" value="<?php echo esc_attr( $tier_key ); ?>" <?php checked( ! $chosen_tiers || in_array( $tier_key, $chosen_tiers, true ) ); ?>> <?php echo esc_html( $tier['label'] ); ?></label>
                    <?php endforeach; ?>
                    <p class="description">Leave all checked to offer every option. Edit the list itself under BosqueGrande Settings.</p>
                </fieldset>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <script>
    (function(){
        var title = document.getElementById('bgev-ed-title');
        var slug = document.getElementById('bgev-ed-slug');
        var setUrl = document.getElementById('bgev-ed-set-url');
        var displayUrl = document.getElementById('bgev-ed-display-url');
        var urlDisplay = document.getElementById('bgev-ed-url-display');
        var urlStatus = document.getElementById('bgev-ed-url-status');
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var urlNonce = <?php echo wp_json_encode( wp_create_nonce( 'bgev_set_url_' . (int) $post->ID ) ); ?>;
        var postId = <?php echo (int) $post->ID; ?>;

        function slugify(value){
            return String(value || '').toLowerCase().trim()
                .normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        }
        if (setUrl) setUrl.addEventListener('click', function(){
            var wanted = slugify(slug.value || (title ? title.value : ''));
            if (!wanted) { urlStatus.textContent = 'Enter an event title or URL ending first.'; return; }
            slug.value = wanted;
            setUrl.disabled = true;
            urlStatus.textContent = 'Setting URL…';
            var body = new URLSearchParams({action:'bgev_set_event_url',nonce:urlNonce,pid:String(postId),slug:wanted});
            fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()})
                .then(function(r){ return r.json(); })
                .then(function(d){
                    setUrl.disabled = false;
                    if (!d || !d.success) { urlStatus.textContent = (d && d.data) ? String(d.data) : 'Could not set the URL.'; return; }
                    if (d.data && d.data.slug) slug.value = String(d.data.slug);
                    if (d.data && d.data.url) urlDisplay.value = String(d.data.url);
                    urlStatus.textContent = 'URL set.';
                })
                .catch(function(){ setUrl.disabled = false; urlStatus.textContent = 'Network error while setting the URL.'; });
        });
        if (displayUrl) displayUrl.addEventListener('click', function(){
            var url = urlDisplay && urlDisplay.value ? urlDisplay.value : '';
            if (!url) { urlStatus.textContent = 'Set or save the event URL first.'; return; }
            window.open(url, '_blank', 'noopener,noreferrer');
        });

        var photoBtn = document.getElementById('bgev-ed-photo-pick');
        var photoClear = document.getElementById('bgev-ed-photo-clear');
        var photoId = document.getElementById('bgev-ed-photo-primary');
        var imageUrl = document.getElementById('bgev_img_url');
        var preview = document.getElementById('bgev-ed-photo-preview');
        var frame;
        if (photoBtn && typeof wp !== 'undefined' && wp.media) photoBtn.addEventListener('click', function(e){
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = wp.media({title:'Choose or upload event photo',button:{text:'Use this photo'},library:{type:'image'},multiple:false});
            frame.on('select', function(){
                var attachment = frame.state().get('selection').first().toJSON();
                photoId.value = attachment.id || 0;
                imageUrl.value = attachment.url || '';
                preview.innerHTML = '';
                var img = document.createElement('img');
                img.src = attachment.url || '';
                img.alt = '';
                preview.appendChild(img);
            });
            frame.open();
        });
        if (photoClear) photoClear.addEventListener('click', function(e){
            e.preventDefault();
            photoId.value = '0';
            imageUrl.value = '';
            preview.innerHTML = '<span>No photo selected</span>';
        });

        var start = document.getElementById('bgev-ed-start-time');
        var end = document.getElementById('bgev-ed-end-time');
        var endTouched = <?php echo $m['_bg_end_time'] ? 'true' : 'false'; ?>;
        if (end) end.addEventListener('input', function(){ endTouched = true; });
        function plusHour(value){
            if (!/^\d{2}:\d{2}$/.test(value || '')) return '';
            var parts=value.split(':');
            var minutes=(parseInt(parts[0],10)*60+parseInt(parts[1],10)+60)%(24*60);
            return String(Math.floor(minutes/60)).padStart(2,'0')+':'+String(minutes%60).padStart(2,'0');
        }
        if (start) start.addEventListener('change', function(){
            if (!endTouched && end) end.value = plusHour(start.value);
        });
    })();
    </script>

    <?php if ( $is_pending ) : ?>
        <hr style="margin:1rem 0;border:none;border-top:1px solid #eee">
        <p style="background:#FFF3CD;padding:8px 10px;border-left:3px solid #C98A1B;border-radius:3px;font-size:.85em">
            <strong>⏳ Pending Review</strong><br>
            Submitted by: <?php echo esc_html( $m['_bg_submitter_name'] ?: '(none)' ); ?><br>
            Email: <?php echo esc_html( $m['_bg_submitter_email'] ?: '(none)' ); ?>
        </p>
        <p>
            <button type="button" id="bgc-approve-btn" class="button button-primary" style="background:#1F5130;border-color:#2A6B40;width:48%;margin-right:2%" data-pid="<?php echo esc_attr( $post->ID ); ?>">✓ Approve</button>
            <button type="button" id="bgc-reject-btn" class="button" style="background:#E53935;color:#fff;border-color:#B71C1C;width:48%" data-pid="<?php echo esc_attr( $post->ID ); ?>">✗ Reject</button>
        </p>
        <div id="bgc-action-result" style="margin-top:8px;font-size:.85em"></div>
        <script>
        (function(){
            var aj='<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
                nc='<?php echo esc_js( wp_create_nonce( 'bgev_review' ) ); ?>';
            function call(action,pid){
                var out=document.getElementById('bgc-action-result');
                out.textContent='Working...';
                fetch(aj,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:'action='+action+'&nonce='+nc+'&pid='+pid}).then(function(r){return r.json()}).then(function(d){
                    out.style.color=d.success?'#1F5130':'#E53935';
                    out.textContent=String(d.data||'No response was returned.');
                    if(d.success)setTimeout(function(){window.location='<?php echo esc_js( admin_url( 'edit.php?post_type=bg_event' ) ); ?>';},1500);
                });
            }
            document.getElementById('bgc-approve-btn').onclick=function(){if(confirm('Approve and publish this event on the site?'))call('bgev_approve',this.dataset.pid);};
            document.getElementById('bgc-reject-btn').onclick=function(){if(confirm('Reject and delete this submission? The submitter will be notified.'))call('bgev_reject',this.dataset.pid);};
        })();
        </script>
    <?php endif; ?>
    <?php
    // Eventbrite actions are explicit administrator operations over the last saved event.
    if ( ! $is_pending && current_user_can( 'manage_options' ) && current_user_can( 'edit_post', $post->ID ) ) :
        $eb_publisher  = \BosqueGrande\Core\Plugin::instance()->container()->get( 'exchange.eventbrite.publisher' );
        $eb_configured = $eb_publisher->configured();
        $eb_link       = $eb_publisher->linkedEventId( (int) $post->ID );
        $eb_error      = is_wp_error( $eb_link ) ? $eb_link->get_error_message() : '';
        $eb_id         = is_wp_error( $eb_link ) ? '' : (string) $eb_link;
        $eb_url        = get_post_meta( $post->ID, '_bg_eb_event_url', true );
        $eb_last       = get_post_meta( $post->ID, '_bg_eb_last_exported', true );
        ?>
        <hr style="margin:1rem 0;border:none;border-top:1px solid #eee">
        <p style="margin:0 0 6px"><strong>Eventbrite</strong></p>
        <?php if ( ! $eb_configured ) : ?>
            <p style="font-size:.85em;color:#666">
                <?php echo esc_html( $eb_publisher->configurationMessage() ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bosquegrande-settings' ) ); ?>">Open BosqueGrande Settings.</a>
            </p>
        <?php elseif ( $eb_error !== '' ) : ?>
            <p style="font-size:.85em;color:#B71C1C"><?php echo esc_html( $eb_error ); ?></p>
        <?php else : ?>
            <?php if ( $eb_id ) : ?>
                <p style="font-size:.85em;color:#1F5130;margin:0 0 8px">
                    Linked: <code><?php echo esc_html( $eb_id ); ?></code>
                    <?php if ( $eb_url ) : ?> · <a href="<?php echo esc_url( $eb_url ); ?>" target="_blank" rel="noopener noreferrer">View on Eventbrite</a><?php endif; ?>
                    <?php if ( $eb_last ) : ?><br><small style="color:#888">Last exported: <?php echo esc_html( $eb_last ); ?></small><?php endif; ?>
                </p>
            <?php else : ?>
                <p style="font-size:.85em;color:#666;margin:0 0 8px">No Eventbrite event is linked yet.</p>
            <?php endif; ?>
            <p style="font-size:.82em;color:#555;margin:0 0 8px">Save or update the event first. This sends only the last saved title, description, date, time, and location. A new event is created as a draft; BosqueGrande does not guess Eventbrite organizer, venue, ticket, image, payout, or publication settings.</p>
            <p style="margin:0">
                <button type="button" id="bgc-eb-btn" class="button" data-pid="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'bg_eventbrite_export_' . (int) $post->ID ) ); ?>">
                    <?php echo $eb_id ? 'Update linked Eventbrite event' : 'Create Eventbrite draft'; ?>
                </button>
            </p>
            <div id="bgc-eb-result" role="status" aria-live="polite" style="margin-top:8px;font-size:.85em"></div>
            <?php if ( $eb_id ) : ?>
                <p style="font-size:.82em;color:#555;margin:12px 0 7px">Registration sync imports one zero-dollar site registration per Eventbrite attendee/ticket, reconciles explicit cancellations and refunds, and retains answers as encrypted source evidence. It does not create payments, subscribe anyone to newsletters, or send reminder or survey email.</p>
                <p style="display:flex;flex-wrap:wrap;gap:6px;margin:0">
                    <button type="button" id="bgc-eb-reg-trial" class="button" data-dry-run="1" data-pid="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'bg_eventbrite_registrations_' . (int) $post->ID ) ); ?>">Preview registration sync</button>
                    <button type="button" id="bgc-eb-reg-sync" class="button button-primary" data-dry-run="0" data-pid="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'bg_eventbrite_registrations_' . (int) $post->ID ) ); ?>">Sync Eventbrite registrations</button>
                </p>
                <div id="bgc-eb-reg-result" role="status" aria-live="polite" style="margin-top:8px;font-size:.85em"></div>
            <?php endif; ?>
            <script>
            (function(){
                var aj='<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
                var btn = document.getElementById('bgc-eb-btn');
                if (!btn) return;
                btn.addEventListener('click', function(){
                    var r = document.getElementById('bgc-eb-result');
                    r.style.color=''; r.textContent = 'Sending the last saved event to Eventbrite…';
                    btn.disabled = true;
                    var body = new URLSearchParams({action:'bgev_eb_export_one',nonce:btn.dataset.nonce||'',pid:btn.dataset.pid||''});
                    fetch(aj, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                    .then(function(resp){ return resp.text().then(function(text){
                        try { return JSON.parse(text); } catch (e) { throw new Error('Eventbrite returned an unreadable response.'); }
                    }); })
                    .then(function(d){
                        btn.disabled = false;
                        r.textContent = '';
                        var status = document.createElement('span');
                        if (d && d.success) {
                            status.style.color='#1F5130';
                            status.appendChild(document.createTextNode(String(d.data.action||'Eventbrite saved')+': '+String(d.data.eb_id||'')));
                            if(d.data.url && /^https:\/\//i.test(String(d.data.url))){
                                status.appendChild(document.createTextNode(' ('));
                                var link=document.createElement('a');
                                link.href=String(d.data.url);link.target='_blank';link.rel='noopener noreferrer';link.textContent='view';
                                status.appendChild(link);status.appendChild(document.createTextNode(')'));
                            }
                            if(d.data.warning){status.appendChild(document.createElement('br'));status.appendChild(document.createTextNode(String(d.data.warning)));}
                            r.appendChild(status);
                        } else {
                            status.style.color='#B71C1C';
                            status.textContent=String(d && d.data && d.data.error || 'Eventbrite export failed.');
                            r.appendChild(status);
                        }
                    })
                    .catch(function(error){ btn.disabled = false; r.style.color='#B71C1C';r.textContent=String(error && error.message || 'Network error while contacting Eventbrite.'); });
                });

                function registrationSync(button){
                    var out=document.getElementById('bgc-eb-reg-result');
                    if(!out)return;
                    var live=button.dataset.dryRun!=='1';
                    if(live && !window.confirm('Sync Eventbrite attendees into this saved event now? No payments, subscriptions, or messages will be created.'))return;
                    var buttons=[document.getElementById('bgc-eb-reg-trial'),document.getElementById('bgc-eb-reg-sync')].filter(Boolean);
                    buttons.forEach(function(item){item.disabled=true;});
                    out.style.color='';out.textContent=live?'Syncing Eventbrite registrations…':'Checking what the registration sync would change…';
                    var body=new URLSearchParams({
                        action:'bgev_eb_sync_registrations',nonce:button.dataset.nonce||'',pid:button.dataset.pid||'',dry_run:button.dataset.dryRun||'0'
                    });
                    fetch(aj,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
                    .then(function(resp){return resp.text().then(function(text){
                        try{return JSON.parse(text);}catch(e){throw new Error('Eventbrite returned an unreadable registration response.');}
                    });})
                    .then(function(data){
                        buttons.forEach(function(item){item.disabled=false;});
                        if(!data||!data.success){out.style.color='#B71C1C';out.textContent=String(data&&data.data&&data.data.error||'Registration sync failed.');return;}
                        var c=data.data.counts||{};
                        var complete=data.data.ok!==false&&Number(c.errors||0)===0;
                        var review=complete&&Number(c.held||0)>0;
                        out.style.color=complete?(review?'#7A4B00':'#1F5130'):'#B71C1C';
                        out.textContent=(data.data.dry_run
                            ?(complete?(review?'Preview complete, review needed: ':'Preview complete: '):'Preview incomplete: ')
                            :(complete?(review?'Sync complete, review needed: ':'Sync complete: '):'Sync incomplete: '))
                            +String(c.attendees||0)+' attendee(s); '
                            +String(c.created||0)+' created, '+String(c.matched||0)+' matched, '
                            +String(c.updated||0)+' updated, '+String(c.cancelled||0)+' cancelled, '
                            +String(c.held||0)+' held for review, '+String(c.errors||0)+' error(s).';
                        if(data.data.note){out.appendChild(document.createElement('br'));out.appendChild(document.createTextNode(String(data.data.note)));}
                    })
                    .catch(function(error){buttons.forEach(function(item){item.disabled=false;});out.style.color='#B71C1C';out.textContent=String(error&&error.message||'Network error while syncing Eventbrite registrations.');});
                }
                var trial=document.getElementById('bgc-eb-reg-trial'),sync=document.getElementById('bgc-eb-reg-sync');
                if(trial)trial.addEventListener('click',function(){registrationSync(trial);});
                if(sync)sync.addEventListener('click',function(){registrationSync(sync);});
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
    <?php
}


/** Save an event's readable address from the event editor. */
function bgev_set_event_url_ajax() {
    $pid = isset( $_POST['pid'] ) ? (int) $_POST['pid'] : 0;
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ) : '';
    if ( ! $pid || ! wp_verify_nonce( $nonce, 'bgev_set_url_' . $pid ) || ! current_user_can( 'edit_post', $pid ) ) {
        wp_send_json_error( 'You do not have permission to set this event URL.', 403 );
    }
    $post = get_post( $pid );
    if ( ! $post instanceof WP_Post || $post->post_type !== 'bg_event' ) {
        wp_send_json_error( 'Event not found.', 404 );
    }
    $slug = sanitize_title( wp_unslash( (string) ( $_POST['slug'] ?? '' ) ) );
    if ( $slug === '' ) $slug = sanitize_title( (string) $post->post_title );
    if ( $slug === '' ) wp_send_json_error( 'Enter an event title or URL ending first.', 400 );

    $updated = wp_update_post( [ 'ID' => $pid, 'post_name' => $slug ], true );
    if ( is_wp_error( $updated ) ) wp_send_json_error( $updated->get_error_message(), 500 );
    $post = get_post( $pid );
    $url = $post && $post->post_status === 'publish'
        ? get_permalink( $post )
        : ( get_preview_post_link( $post ) ?: get_permalink( $post ) );
    wp_send_json_success( [
        'slug' => $post ? (string) $post->post_name : $slug,
        'url'  => (string) $url,
    ] );
}
add_action( 'wp_ajax_bgev_set_event_url', 'bgev_set_event_url_ajax' );

/* Shortcodes: [bosquegrande] is the complete public application; the
   others render one piece of it on their own. */
add_shortcode('bosquegrande', 'bgev_render_complete_app');
add_shortcode('bg_calendar', 'bgev_render');
add_shortcode('bg_filter', 'bg_tag_filter');
add_shortcode('bg_submit', 'bgev_submit_form');

add_action('init', 'bgev_register_cpt', 5);
add_filter('the_content', 'bgev_scrub_legacy_links', 8);

/**
 * Build the complete, theme-independent single-event card.
 *
 * $filteredContent is used by the compatibility the_content filter. The
 * plugin-owned document omits it and lets WordPress run the normal content
 * pipeline once, with a guard that prevents the compatibility filter from
 * recursively wrapping the description.
 */
function bgev_single_event_content_context( ?bool $set = null ): bool {
    static $active = false;
    if ( $set !== null ) $active = $set;
    return $active;
}

function bgev_render_single_event( int $id, ?string $filteredContent = null ): string {
    $event = get_post( $id );
    if ( ! $event instanceof WP_Post || $event->post_type !== 'bg_event' ) return '';
    $protected = post_password_required( $event );

    if ( $filteredContent === null ) {
        // Never execute embeds or shortcodes from protected content until the
        // visitor has supplied its password. The normal content pipeline can
        // have side effects even when its eventual HTML is not displayed.
        if ( $protected ) {
            $filteredContent = '';
        } else {
            bgev_single_event_content_context( true );
            try {
                $filteredContent = apply_filters( 'the_content', (string) $event->post_content );
            } finally {
                bgev_single_event_content_context( false );
            }
        }
    }

    $wa      = bgev_registration_link( $id );
    $return  = bgev_calendar_return_url();
    $dt      = bgev_date( $id );
    $tm      = bgev_time_range( $id );
    $tags    = bg_tags( $id );
    $btn     = bg_str( 'register_button', 'REGISTER' );
    $title   = get_the_title( $id );
    $raw     = trim( (string) $filteredContent );
    $img     = bgev_img( $id );
    // Match WordPress's normal single-post contract: the_content has already
    // run its shortcode, embed, block, and author-capability filters. A second
    // wp_kses_post pass here would silently strip legitimate iframe/embed or
    // form output that worked before the app-shell change.
    $cleaned = $protected
        ? get_the_password_form( $event )
        : ( $raw !== '' ? bgev_clean( $raw ) : '' );
    if ( ! $protected && $cleaned !== '' ) {
        // Imported/TinyMCE descriptions may contain an H1. The page already
        // owns its one accessible H1, so description-level H1s become H2s.
        $cleaned = preg_replace( '/<(\/?)h1(\b[^>]*)>/i', '<$1h2$2>', (string) $cleaned );
    }

    $startDate = (string) get_post_meta( $id, '_bg_start_date', true );
    $endDate = (string) get_post_meta( $id, '_bg_end_date', true );
    if ( $dt !== '' && $endDate !== '' && $endDate !== $startDate ) {
        $endTs = strtotime( $endDate );
        if ( $endTs ) $dt .= ' – ' . date_i18n( 'l, F j, Y', $endTs );
    }
    $start24 = bgev_format_time_value( get_post_meta( $id, '_bg_start_time', true ), 'H:i' );
    $startIso = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $startDate )
        ? $startDate . ( preg_match( '/^\d{2}:\d{2}$/', $start24 ) ? 'T' . $start24 : '' )
        : '';
    $location = trim( (string) get_post_meta( $id, '_bg_location_text', true ) );

    // Promote one image to the hero without deleting unrelated inline media.
    if ( $img && $cleaned && ! $protected ) {
        $cleaned = bgev_remove_duplicate_hero_images( $cleaned, $img );
    }

    // Media-only and embed-only descriptions are meaningful even when text
    // stripping yields an empty string.
    $hasDescription = $protected || trim( (string) $cleaned ) !== '';
    $past = bgev_event_is_past( $id );
    ob_start();
    echo '<article class="bgc-single" aria-labelledby="bg-event-title" itemscope itemtype="https://schema.org/Event">';
    echo '<nav class="bgc-single-nav" aria-label="' . esc_attr( bgev_wording( 'event_navigation_aria', 'Event navigation' ) ) . '"><a class="bgc-return-calendar" href="' . esc_url( $return ) . '">&larr; ' . esc_html( bgev_wording( 'event_return_calendar', 'Return to Calendar' ) ) . '</a></nav>';
    echo '<header class="bgc-single-header">';
    echo '<p class="bgc-single-eyebrow">' . esc_html( bgev_wording( 'event_eyebrow', 'Community event' ) ) . '</p>';
    echo '<h1 id="bg-event-title" class="bgc-single-title" itemprop="name">' . esc_html( $title ?: bgev_wording( 'event_fallback_title', 'Event details' ) ) . '</h1>';
    echo '</header>';
    if ( $img ) {
        echo '<figure class="bgc-single-hero" itemprop="image"><img src="' . esc_url( $img ) . '" alt="' . esc_attr( $title ) . '" loading="eager" fetchpriority="high" decoding="async"></figure>';
    }
    if ( $dt || $tm || $location ) {
        echo '<dl class="bgc-single-details" aria-label="' . esc_attr( bgev_wording( 'event_details_aria', 'Event date, time, and location' ) ) . '">';
        if ( $dt ) {
            echo '<div class="bgc-single-detail"><dt>' . esc_html( bgev_wording( 'event_date_label', 'Date' ) ) . '</dt><dd><time itemprop="startDate"' . ( $startIso !== '' ? ' datetime="' . esc_attr( $startIso ) . '"' : '' ) . '>' . esc_html( $dt ) . '</time></dd></div>';
        }
        if ( $tm ) echo '<div class="bgc-single-detail"><dt>' . esc_html( bgev_wording( 'event_time_label', 'Time' ) ) . '</dt><dd>' . esc_html( $tm ) . '</dd></div>';
        if ( $location ) echo '<div class="bgc-single-detail"><dt>' . esc_html( bgev_wording( 'event_location_label', 'Location' ) ) . '</dt><dd>' . esc_html( $location ) . '</dd></div>';
        echo '</dl>';
    }
    if ( $hasDescription ) echo '<div class="bgc-single-content" itemprop="description">' . $cleaned . '</div>';
    // Registration remains after the event particulars: the canonical CTA.
    if ( $wa && ! $past ) {
        echo '<div class="bgc-single-reg"><a href="' . esc_url( $wa ) . '" class="bgc-reg-btn bgc-reg-lg">' . esc_html( $btn ) . '</a></div>';
    } elseif ( $past ) {
        echo '<p class="bgc-single-ended" role="status">' . esc_html( bgev_wording( 'event_ended', 'This event has ended.' ) ) . '</p>';
    }
    if ( $tags ) {
        echo '<div class="bgc-single-tags" aria-label="' . esc_attr( bgev_wording( 'event_categories_aria', 'Event categories' ) ) . '"><span class="bg-event-visually-hidden">' . esc_html( bgev_wording( 'event_categories_label', 'Event categories:' ) ) . '</span>';
        foreach ( $tags as $t ) {
            $axis = isset( bgev_location_tags()[ $t->slug ] ) ? 'location' : 'interest';
            echo '<span class="bgc-tag-pill">' . esc_html( bgev_public_tag_label( $axis, $t->slug, $t->name ) ) . '</span>';
        }
        echo '</div>';
    }
    echo '</article>';
    return ob_get_clean();
}

/** Compatibility for an intentional downstream template override. */
function bgev_single_event_content_fallback( $content ) {
    if ( bgev_single_event_content_context() ) return $content;
    if ( ! is_singular( 'bg_event' ) || ! in_the_loop() || ! is_main_query() ) return $content;
    return bgev_render_single_event(
        get_the_ID(),
        post_password_required() ? null : (string) $content
    );
}
add_filter( 'the_content', 'bgev_single_event_content_fallback', 99 );

add_action( 'save_post_bg_event', function( $pid ) {
    if ( ! isset( $_POST['bgev_mb_n'] ) || ! wp_verify_nonce( wp_unslash( $_POST['bgev_mb_n'] ), 'bgev_mb' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $pid ) ) return;

    $start_date = isset( $_POST['bgev_sd'] )
        ? sanitize_text_field( wp_unslash( (string) $_POST['bgev_sd'] ) )
        : '';
    $end_date = isset( $_POST['bgev_ed'] )
        ? sanitize_text_field( wp_unslash( (string) $_POST['bgev_ed'] ) )
        : '';
    if ( isset( $_POST['bgev_sd'] ) ) update_post_meta( $pid, '_bg_start_date', $start_date );
    if ( isset( $_POST['bgev_ed'] ) ) {
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date )
            && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date )
            && $end_date < $start_date ) {
            $end_date = $start_date;
            add_filter( 'redirect_post_location', static function( $location ) {
                return add_query_arg( 'bg_event_date_adjusted', '1', $location );
            }, 99 );
        }
        update_post_meta( $pid, '_bg_end_date', $end_date );
    }

    $text_fields = [
        'bgev_location_text' => '_bg_location_text',
    ];
    foreach ( $text_fields as $f => $k ) {
        if ( isset( $_POST[ $f ] ) ) update_post_meta( $pid, $k, sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) );
    }

    $start_time = isset( $_POST['bgev_st'] ) ? bgev_time_storage_value( wp_unslash( (string) $_POST['bgev_st'] ) ) : '';
    if ( isset( $_POST['bgev_st'] ) ) update_post_meta( $pid, '_bg_start_time', $start_time );

    if ( isset( $_POST['bgev_et'] ) ) {
        $end_time = bgev_time_storage_value( wp_unslash( (string) $_POST['bgev_et'] ) );
        if ( $end_time === '' && $start_time !== '' ) {
            $start24 = bgev_format_time_value( $start_time, 'H:i' );
            $dt = preg_match( '/^\d{2}:\d{2}$/', $start24 )
                ? DateTimeImmutable::createFromFormat( '!H:i', $start24, wp_timezone() )
                : false;
            if ( $dt ) $end_time = $dt->modify( '+1 hour' )->format( 'g:i A' );
        }
        update_post_meta( $pid, '_bg_end_time', $end_time );

        // A one-hour fallback or an explicitly earlier clock time can cross
        // midnight. When no end date was entered, keep the calendar's last-day
        // semantics correct by carrying the end date into the following day.
        $start24 = bgev_format_time_value( $start_time, 'H:i' );
        $end24 = bgev_format_time_value( $end_time, 'H:i' );
        if ( ( $end_date === '' || $end_date === $start_date )
            && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date )
            && preg_match( '/^\d{2}:\d{2}$/', $start24 ) && preg_match( '/^\d{2}:\d{2}$/', $end24 )
            && $end24 <= $start24 ) {
            $day = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, wp_timezone() );
            if ( $day ) update_post_meta( $pid, '_bg_end_date', $day->modify( '+1 day' )->format( 'Y-m-d' ) );
        }
    }

    if ( isset( $_POST['bgev_reg'] ) ) update_post_meta( $pid, '_bg_reg_url', esc_url_raw( wp_unslash( $_POST['bgev_reg'] ) ) );
    if ( isset( $_POST['bgev_tiers_present'] ) ) {
        $all_tiers = array_keys( \BosqueGrande\Modules\Registration\Tiers::all() );
        $picked = array_values( array_intersect( $all_tiers, array_map( 'sanitize_key', (array) wp_unslash( $_POST['bgev_tiers'] ?? [] ) ) ) );
        // Every option checked (or none) means "offer them all", which also
        // picks up options added to the catalog later.
        if ( ! $picked || count( $picked ) === count( $all_tiers ) ) {
            delete_post_meta( $pid, \BosqueGrande\Modules\Registration\Tiers::EVENT_META );
        } else {
            update_post_meta( $pid, \BosqueGrande\Modules\Registration\Tiers::EVENT_META, $picked );
        }
    }

    // Image gets manual-flag tracking. If admin changes the image
    // (or empties it), respect that decision on subsequent syncs.
    if ( isset( $_POST['bgev_img'] ) ) {
        $old = get_post_meta( $pid, '_bg_image', true );
        $new = esc_url_raw( wp_unslash( $_POST['bgev_img'] ) );
        update_post_meta( $pid, '_bg_image', $new );
        if ( $new !== $old ) {
            if ( $new ) {
                update_post_meta( $pid, '_bg_image_manual', '1' );
            } else {
                // Empty field = unlock and let next sync repopulate from API
                delete_post_meta( $pid, '_bg_image_manual' );
            }
        }
    }
} );

add_action( 'admin_notices', function() {
    if ( ! current_user_can( 'edit_posts' ) || (string) ( $_GET['bg_event_date_adjusted'] ?? '' ) !== '1' ) return;
    echo '<div class="notice notice-warning is-dismissible"><p><strong>BosqueGrande adjusted the event end date.</strong> An event cannot end before it starts, so the end date was set to the start date. Review the schedule before publishing.</p></div>';
} );


function bgev_email_layout( $title, $body_inner ) {
    $s = get_option( BGEV_OPT, [] );
    $primary = $s['color_primary'] ?? '#1F5130';
    $accent  = $s['color_accent']  ?? '#C98A1B';
    $sitename = get_bloginfo( 'name' );
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html( $title ) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;color:#1F2A24">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:30px 0">'
        . '<tr><td align="center">'
        . '<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)">'
        . '<tr><td style="background:linear-gradient(135deg,' . esc_attr( $primary ) . ' 0%,' . esc_attr( $primary ) . ' 60%,' . esc_attr( $accent ) . ' 130%);padding:24px 30px;color:#fff">'
        . '<h1 style="margin:0;font-size:1.3rem;color:#fff">' . esc_html( $sitename ) . '</h1>'
        . '<p style="margin:4px 0 0;color:rgba(255,255,255,.8);font-size:.9rem">' . esc_html( bgev_wording( 'suggest_email_kicker', 'Event Calendar' ) ) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:30px">' . $body_inner . '</td></tr>'
        . '<tr><td style="padding:18px 30px;background:#fafafa;border-top:1px solid #eee;color:#888;font-size:.78rem">'
        . bgev_wording_html(
            'suggest_email_footer',
            "You're receiving this email because of an event suggestion submitted at {site}. If this wasn't you, no action is needed; the request will expire automatically.",
            [ 'site' => esc_html( home_url() ) ]
        )
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function bgev_send_html_email( $to, $subject, $body_html ) {
    $filter = function() { return 'text/html'; };
    add_filter( 'wp_mail_content_type', $filter );
    $sent = wp_mail( $to, $subject, $body_html );
    remove_filter( 'wp_mail_content_type', $filter );
    return $sent;
}

function bgev_email_verification( $email, $name, $event_title, $token ) {
    $verify_url = add_query_arg( 'bgev_verify', $token, home_url( '/' ) );
    $body = '<h2 style="margin-top:0;color:#1F5130">' . esc_html( bgev_wording( 'suggest_email_verify_heading', 'Confirm your event suggestion' ) ) . '</h2>'
        . '<p>' . bgev_wording_html( 'suggest_email_greeting', 'Hi {name},', [ 'name' => esc_html( $name ) ] ) . '</p>'
        . '<p>' . bgev_wording_html(
            'suggest_email_verify_body',
            'Thanks for suggesting {event}. To complete your submission, please confirm by clicking the button below.',
            [ 'event' => '<strong>' . esc_html( $event_title ) . '</strong>' ]
        ) . '</p>'
        . '<p style="margin:30px 0;text-align:center"><a href="' . esc_url( $verify_url ) . '" style="display:inline-block;background:#1F5130;color:#fff;padding:14px 30px;border-radius:8px;text-decoration:none;font-weight:600">' . esc_html( bgev_wording( 'suggest_email_verify_button', 'Confirm submission' ) ) . '</a></p>'
        . '<p style="font-size:.85rem;color:#666">' . esc_html( bgev_wording( 'suggest_email_verify_paste', 'Or paste this link into your browser:' ) ) . '<br><a href="' . esc_url( $verify_url ) . '" style="color:#1F5130;word-break:break-all">' . esc_html( $verify_url ) . '</a></p>'
        . '<p style="font-size:.85rem;color:#666">' . esc_html( bgev_wording(
            'suggest_email_verify_expiry',
            "This link expires in {hours} hours. If you didn't submit this event, you can safely ignore this email.",
            [ 'hours' => (string) BGEV_SUGGESTION_VERIFY_HOURS ]
        ) ) . '</p>';
    return bgev_send_html_email(
        $email,
        sanitize_text_field( bgev_wording( 'suggest_email_verify_subject', 'Confirm your event suggestion: {event}', [ 'event' => $event_title ] ) ),
        bgev_email_layout( bgev_wording( 'suggest_email_verify_layout_title', 'Confirm submission' ), $body )
    );
}

function bgev_email_admin_notification( $pending, $wp_pid, $test_mode ) {
    $s = get_option( BGEV_OPT, [] );
    $contact = $s['designated_contact_email'] ?? get_option( 'admin_email' );
    if ( ! $contact ) return false;

    $edit_url = admin_url( 'post.php?post=' . $wp_pid . '&action=edit' );
    $tag_lines = [];
    $all_tags = ( bgev_location_tags() + bgev_interest_tags() );
    if ( $pending['loc_tag'] && isset( $all_tags[ $pending['loc_tag'] ] ) ) $tag_lines[] = 'Location: ' . $all_tags[ $pending['loc_tag'] ];
    if ( $pending['int_tag'] && isset( $all_tags[ $pending['int_tag'] ] ) ) $tag_lines[] = 'Interest: ' . $all_tags[ $pending['int_tag'] ];

    $body = '<h2 style="margin-top:0;color:#1F5130">New event suggestion</h2>';
    if ( $test_mode ) $body .= '<p style="background:#FFF3CD;padding:8px 12px;border-left:3px solid #C98A1B;border-radius:3px;font-size:.85rem"><strong>Note</strong>: submissions are kept in WordPress only; the LGL build does not write to your donor CRM.</p>';
    $body .= '<table cellpadding="6" style="width:100%;border-collapse:collapse;margin:1rem 0">';
    $body .= '<tr><td style="padding:6px 10px;background:#f5f5f5;width:130px;font-weight:600;border:1px solid #eee">Title</td><td style="padding:6px 10px;border:1px solid #eee">' . esc_html( $pending['title'] ) . '</td></tr>';
    $body .= '<tr><td style="padding:6px 10px;background:#f5f5f5;font-weight:600;border:1px solid #eee">Submitter</td><td style="padding:6px 10px;border:1px solid #eee">' . esc_html( $pending['name'] ) . ' &lt;' . esc_html( $pending['email'] ) . '&gt;</td></tr>';
    $body .= '<tr><td style="padding:6px 10px;background:#f5f5f5;font-weight:600;border:1px solid #eee">Start</td><td style="padding:6px 10px;border:1px solid #eee">' . esc_html( $pending['start_dt'] ) . '</td></tr>';
    if ( $pending['end_dt'] ) $body .= '<tr><td style="padding:6px 10px;background:#f5f5f5;font-weight:600;border:1px solid #eee">End</td><td style="padding:6px 10px;border:1px solid #eee">' . esc_html( $pending['end_dt'] ) . '</td></tr>';
    $body .= '<tr><td style="padding:6px 10px;background:#f5f5f5;font-weight:600;border:1px solid #eee">Location</td><td style="padding:6px 10px;border:1px solid #eee">' . esc_html( $pending['location'] ) . '</td></tr>';
    if ( $pending['reg_url'] ) $body .= '<tr><td style="padding:6px 10px;background:#f5f5f5;font-weight:600;border:1px solid #eee">Reg URL</td><td style="padding:6px 10px;border:1px solid #eee"><a href="' . esc_url( $pending['reg_url'] ) . '">' . esc_html( $pending['reg_url'] ) . '</a></td></tr>';
    if ( $tag_lines ) $body .= '<tr><td style="padding:6px 10px;background:#f5f5f5;font-weight:600;border:1px solid #eee">Suggested</td><td style="padding:6px 10px;border:1px solid #eee">' . esc_html( implode( ' / ', $tag_lines ) ) . '</td></tr>';
    $body .= '</table>';

    $body .= '<h3 style="color:#1F5130;font-size:1rem">Description</h3>';
    $body .= '<div style="background:#fafafa;padding:12px 16px;border-radius:6px;border:1px solid #eee">' . wp_kses_post( $pending['description'] ) . '</div>';

    $body .= '<p style="margin:24px 0;text-align:center">';
    $body .= '<a href="' . esc_url( $edit_url ) . '" style="display:inline-block;background:#1F5130;color:#E0A93B;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600">Review in admin</a>';
    $body .= '</p>';

    return bgev_send_html_email(
        $contact,
        'New event suggestion: ' . $pending['title'],
        bgev_email_layout( 'New event suggestion', $body )
    );
}

function bgev_email_review_result( $email, $name, $title, $approved ) {
    if ( ! is_email( $email ) ) return false;
    $heading = $approved
        ? bgev_wording( 'suggest_email_approved_heading', 'Your event suggestion was approved' )
        : bgev_wording( 'suggest_email_reviewed_heading', 'Your event suggestion was reviewed' );
    $message = $approved
        ? bgev_wording_html( 'suggest_email_approved_message', 'Thank you! Your suggestion for {event} has been approved and published.', [ 'event' => '<strong>' . esc_html( $title ) . '</strong>' ] )
        : bgev_wording_html( 'suggest_email_reviewed_message', 'Thank you for suggesting {event}. It was reviewed but was not published.', [ 'event' => '<strong>' . esc_html( $title ) . '</strong>' ] );
    $body = '<h2 style="margin-top:0;color:#1F5130">' . esc_html( $heading ) . '</h2>'
        . '<p>' . bgev_wording_html( 'suggest_email_greeting', 'Hi {name},', [ 'name' => esc_html( $name ?: bgev_wording( 'suggest_email_fallback_name', 'there' ) ) ] ) . '</p><p>' . $message . '</p>';
    return bgev_send_html_email(
        $email,
        sanitize_text_field( bgev_wording( 'suggest_email_result_subject', '{heading}: {event}', [ 'heading' => $heading, 'event' => $title ] ) ),
        bgev_email_layout( $heading, $body )
    );
}

function bgev_clear_submitter_meta( $pid ) {
    foreach ( [ '_bg_submitter_name', '_bg_submitter_email', '_bg_submitter_ip' ] as $key ) {
        delete_post_meta( $pid, $key );
    }
}

function bgev_review_submission( $approve ) {
    check_ajax_referer( 'bgev_review', 'nonce' );
    $pid = isset( $_POST['pid'] ) ? (int) $_POST['pid'] : 0;
    $post = $pid ? get_post( $pid ) : null;
    $can_review = current_user_can( 'edit_post', $pid )
        && ( $approve ? current_user_can( 'publish_posts' ) : current_user_can( 'delete_post', $pid ) );
    if ( ! $post || $post->post_type !== 'bg_event' || $post->post_status !== 'pending'
        || ! $can_review
    ) {
        wp_send_json_error( 'This pending suggestion is unavailable or you do not have permission to review it.', 403 );
    }

    $encryption = \BosqueGrande\Core\Plugin::instance()->container()->get( 'encryption' );
    $name = $encryption->decrypt(
        (string) get_post_meta( $pid, '_bg_submitter_name', true ),
        'event-meta:' . $pid . ':_bg_submitter_name'
    );
    $email = $encryption->decrypt(
        (string) get_post_meta( $pid, '_bg_submitter_email', true ),
        'event-meta:' . $pid . ':_bg_submitter_email'
    );
    if ( $approve ) {
        $updated = wp_update_post( [
            'ID' => $pid,
            'post_status' => 'publish',
            'post_name' => sanitize_title( (string) $post->post_title ),
        ], true );
        if ( is_wp_error( $updated ) ) {
            wp_send_json_error( 'The event could not be published: ' . $updated->get_error_message(), 500 );
        }
    } elseif ( ! wp_trash_post( $pid ) ) {
        wp_send_json_error( 'The suggestion could not be moved to the Trash.', 500 );
    }

    $mailed = bgev_email_review_result( $email, $name, (string) $post->post_title, (bool) $approve );
    bgev_clear_submitter_meta( $pid );
    bgev_log( sprintf( 'Suggestion #%d %s by an administrator.', $pid, $approve ? 'approved' : 'rejected' ) );
    $message = $approve ? 'Published' : 'Moved to Trash';
    if ( is_email( $email ) && ! $mailed ) $message .= '; submitter notification email failed';
    wp_send_json_success( $message );
}

add_action( 'wp_ajax_bgev_approve', function() {
    bgev_review_submission( true );
} );
add_action( 'wp_ajax_bgev_reject', function() {
    bgev_review_submission( false );
} );

function bgev_render_verification_page( $success, $message, $confirmation_token = '' ) {
    $s        = get_option( BGEV_OPT, [] );
    $primary  = sanitize_hex_color( (string) ( $s['color_primary'] ?? '' ) ) ?: '#1F5130';
    $accent   = sanitize_hex_color( (string) ( $s['color_accent'] ?? '' ) ) ?: '#C98A1B';
    $success_color = sanitize_hex_color( (string) ( $s['color_success'] ?? '' ) ) ?: '#1F5130';
    $sitename = get_bloginfo( 'name' );
    $confirming = is_string( $confirmation_token ) && $confirmation_token !== '';
    $title    = $confirming
        ? bg_str( 'verification_confirm_title', 'Confirm Event Suggestion' )
        : ( $success
            ? bg_str( 'verification_success_title', 'Submission Verified' )
            : bg_str( 'verification_failure_title', 'Verification Failed' ) );
    $bar      = ( $success || $confirming ) ? $success_color : '#E53935';

    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    nocache_headers();
    status_header( ( $success || $confirming ) ? 200 : 400 );
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'Referrer-Policy: no-referrer', true );
    header( 'X-Robots-Tag: noindex, nofollow', true );

    // The bearer token has already been copied into local state. Remove it
    // from request globals and the server-visible URI before wp_head runs so
    // later SEO, analytics, or consent hooks cannot copy it into markup or
    // telemetry. The confirmation form below uses only the local value.
    if ( isset( $_SERVER['REQUEST_URI'] ) ) {
        $_SERVER['REQUEST_URI'] = remove_query_arg(
            'bgev_verify',
            wp_unslash( (string) $_SERVER['REQUEST_URI'] )
        );
    }
    if ( isset( $_SERVER['QUERY_STRING'] ) ) {
        $query = [];
        parse_str( wp_unslash( (string) $_SERVER['QUERY_STRING'] ), $query );
        unset( $query['bgev_verify'] );
        $_SERVER['QUERY_STRING'] = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
    }
    unset(
        $_GET['bgev_verify'],
        $_POST['bgev_verify_confirm'],
        $_REQUEST['bgev_verify'],
        $_REQUEST['bgev_verify_confirm']
    );

    ob_start();
    ?>
    <div class="bg-verification-card" style="--bg-verification-bar:<?php echo esc_attr( $bar ); ?>;--bg-verification-primary:<?php echo esc_attr( $primary ); ?>;--bg-verification-accent:<?php echo esc_attr( $accent ); ?>">
        <span class="bg-verification-icon" aria-hidden="true"><?php echo ( $success || $confirming ) ? '✅' : '⚠️'; ?></span>
        <p><?php echo esc_html( (string) $message ); ?></p>
        <?php if ( $confirming ) : ?>
            <form method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                <input type="hidden" name="bgev_verify_confirm" value="<?php echo esc_attr( $confirmation_token ); ?>">
                <button type="submit"><?php echo esc_html( bg_str( 'verification_confirm_button', 'Confirm suggestion' ) ); ?></button>
            </form>
        <?php else : ?>
            <p class="bg-verification-action"><a class="wp-element-button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( bg_str( 'verification_return_home', 'Return home' ) ); ?></a></p>
        <?php endif; ?>
    </div>
    <?php
    $content = (string) ob_get_clean();
    $documentTitle = static fn(): string => $title . ', ' . $sitename;
    $cleanVerificationLocation = static function (): void {
        $target = wp_json_encode( home_url( '/' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        echo '<script>if(window.history&&window.history.replaceState){window.history.replaceState(null,document.title,'
            . $target . ');}</script>';
    };
    add_filter( 'pre_get_document_title', $documentTitle, PHP_INT_MAX );
    // Remove the one-time token from the address bar before any ordinary
    // wp_head integration can inspect location.href. The server-side token in
    // the confirmation form remains unchanged.
    add_action( 'wp_head', $cleanVerificationLocation, -PHP_INT_MAX );
    bgev_output_owned_document( $content, [
        'context' => 'verification',
        'body_class' => 'bg-verification-page',
        'main_id' => 'bg-verification-main',
        'title' => $title,
        'eyebrow' => bg_str( 'verification_eyebrow', 'Event suggestion' ),
        'skip_label' => bg_str( 'verification_skip', 'Skip to verification result' ),
        'no_cache' => true,
    ] );
    remove_action( 'wp_head', $cleanVerificationLocation, -PHP_INT_MAX );
    remove_filter( 'pre_get_document_title', $documentTitle, PHP_INT_MAX );
    exit;
}

/** Atomically reserve one unit of the public event-suggestion mail budget. */
function bgev_claim_suggestion_mail_budget( string $scope, int $limit, int $window ): bool {
    $hash = md5( $scope );
    $lock = 'bgev_mail_lock_' . $hash;
    $owner = \BosqueGrande\Core\OptionLease::acquire( $lock, 10 );
    if ( $owner === '' ) return false;
    try {
        $key = 'bgev_mail_budget_' . $hash;
        $state = get_transient( $key );
        $count = is_array( $state ) ? (int) ( $state['count'] ?? 0 ) : 0;
        if ( $count >= $limit ) return false;
        return set_transient( $key, [ 'count' => $count + 1 ], max( 1, $window ) );
    } finally {
        \BosqueGrande\Core\OptionLease::release( $lock, $owner );
    }
}

/** Return the event retained by a completed one-use verification marker. */
function bgev_verified_suggestion_post_id( mixed $marker ): int {
    if ( ! is_array( $marker ) || ( $marker['state'] ?? '' ) !== 'complete' ) return 0;
    $post_id = (int) ( $marker['post_id'] ?? 0 );
    if ( $post_id < 1 || get_post_type( $post_id ) !== 'bg_event' || get_post_status( $post_id ) === 'trash' ) {
        return 0;
    }
    return $post_id;
}

/**
 * Store one scalar event-suggestion value idempotently.
 *
 * WordPress returns false when update_post_meta() receives the value already
 * stored. A resumed verification must treat that no-op as success while still
 * distinguishing it from a failed write or a missing row.
 */
function bgev_store_suggestion_meta( int $post_id, string $key, string $value ): bool {
    if ( update_post_meta( $post_id, $key, $value ) !== false ) return true;
    return metadata_exists( 'post', $post_id, $key )
        && (string) get_post_meta( $post_id, $key, true ) === $value;
}

add_action( 'bgev_cleanup_verification_marker', static function( string $option ): void {
    if ( preg_match( '/^bgev_verified_[a-f0-9]{64}$/', $option ) ) delete_option( $option );
}, 10, 1 );

function bgev_handle_submission( WP_REST_Request $req ) {
    $s = get_option( BGEV_OPT, [] );
    if ( empty( $s['enable_submissions'] ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => bgev_wording( 'suggest_api_disabled', 'Submissions are not enabled.' ) ], 403 );
    }

    $params = $req->get_params();

    // Honeypot: silent fake-success.
    if ( ! empty( $params['bgev_hp'] ) ) {
        return new WP_REST_Response( [ 'success' => true, 'message' => bgev_wording( 'suggest_api_honeypot_success', 'Thanks. Check your email.' ) ], 200 );
    }

    // Client-side maxlength attributes are guidance, not an admission
    // boundary. Reject array-shaped or oversized direct REST requests before
    // sanitizers, date parsing, encryption, or mail-budget work is attempted.
    $field_limits = [
        'name' => 100,
        'email' => 200,
        'title' => 200,
        'start_date' => 10,
        'start_time' => 8,
        'end_date' => 10,
        'end_time' => 8,
        'location' => 200,
        'reg_url' => 500,
        'description' => 3000,
        'loc_tag' => 80,
        'int_tag' => 80,
        'bgev_ts' => 20,
    ];
    foreach ( $field_limits as $field => $limit ) {
        if ( ! isset( $params[ $field ] ) ) continue;
        if ( ! is_scalar( $params[ $field ] ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => bgev_wording( 'suggest_api_invalid_request', 'The submission contains an invalid field value. Please reload the page and try again.' ),
            ], 400 );
        }
        $value = (string) $params[ $field ];
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
        if ( ! is_int( $length ) ) $length = strlen( $value );
        if ( $length > $limit ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => bgev_wording( 'suggest_api_too_long', 'One or more fields is too long. Please shorten the entry and try again.' ),
            ], 400 );
        }
    }

    // Time check: sub-3-second submissions are bots.
    $ts = (int) ( $params['bgev_ts'] ?? 0 );
    if ( $ts && ( time() - $ts ) < 3 ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => bgev_wording( 'suggest_api_slow_down', 'Please slow down.' ) ], 429 );
    }

    // Required fields
    $required = [ 'name', 'email', 'title', 'start_date', 'start_time', 'location', 'description' ];
    foreach ( $required as $f ) {
        if ( empty( trim( (string) ( $params[ $f ] ?? '' ) ) ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => bgev_wording( 'suggest_api_missing_field', 'Missing required field: {field}', [ 'field' => $f ] ),
            ], 400 );
        }
    }

    $email = sanitize_email( $params['email'] );
    if ( ! is_email( $email ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => bgev_wording( 'suggest_api_invalid_email', 'Please provide a valid email address.' ) ], 400 );
    }

    $ip = isset( $_SERVER['REMOTE_ADDR'] )
        ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
        : 'unknown';
    $target = hash( 'sha256', strtolower( $email ) );
    $repeat = $target . '|' . hash( 'sha256', strtolower( sanitize_text_field( (string) $params['title'] ) ) );

    // Date/time parsing
    $start_date = sanitize_text_field( $params['start_date'] );
    $start_time = sanitize_text_field( $params['start_time'] );
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{1,2}:\d{2}/', $start_time ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => bgev_wording( 'suggest_api_invalid_datetime', 'Invalid date or time format.' ) ], 400 );
    }
    $tz       = wp_timezone();
    $start_dt = DateTime::createFromFormat( 'Y-m-d H:i', $start_date . ' ' . substr( $start_time, 0, 5 ), $tz );
    $start_errors = DateTime::getLastErrors();
    if ( ! $start_dt || ( is_array( $start_errors ) && ( $start_errors['warning_count'] || $start_errors['error_count'] ) ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => bgev_wording( 'suggest_api_parse_start', 'Could not parse start date/time.' ) ], 400 );
    }
    if ( $start_dt->getTimestamp() < time() - 86400 ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => bgev_wording( 'suggest_api_past_start', 'Event start must not be in the past.' ) ], 400 );
    }
    $end_date = sanitize_text_field( $params['end_date'] ?? '' );
    $end_time = sanitize_text_field( $params['end_time'] ?? '' );
    if ( $end_date === '' ) $end_date = $start_date;
    if ( $end_time === '' ) {
        $end_dt = ( clone $start_dt )->modify( '+1 hour' );
    } else {
        $end_dt = DateTime::createFromFormat( 'Y-m-d H:i', $end_date . ' ' . substr( $end_time, 0, 5 ), $tz );
        $end_errors = DateTime::getLastErrors();
        if ( ! $end_dt || ( is_array( $end_errors ) && ( $end_errors['warning_count'] || $end_errors['error_count'] ) ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => bgev_wording( 'suggest_api_parse_end', 'Could not parse end date/time.' ) ], 400 );
        }
        // An end clock earlier than the start clock with no explicit later
        // date is treated as an overnight event rather than rejected.
        if ( $end_dt <= $start_dt && sanitize_text_field( $params['end_date'] ?? '' ) === '' ) {
            $end_dt->modify( '+1 day' );
        }
        if ( $end_dt <= $start_dt ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => bgev_wording( 'suggest_api_end_before_start', 'Event end must be after its start.' ) ], 400 );
        }
    }

    // Description: kses with strict whitelist (no <img>)
    $allowed_html = [
        'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [],
        'a' => [ 'href' => [], 'title' => [] ],
        'ul' => [], 'ol' => [], 'li' => [],
    ];
    $description_html = wp_kses( wp_unslash( $params['description'] ), $allowed_html );

    // Build pending record
    $token   = wp_generate_password( 32, false );
    $pending = [
        'token'        => $token,
        'name'         => sanitize_text_field( $params['name'] ),
        'email'        => $email,
        'title'        => sanitize_text_field( $params['title'] ),
        'start_dt'     => $start_dt->format( 'c' ),
        'end_dt'       => $end_dt ? $end_dt->format( 'c' ) : '',
        'location'     => sanitize_text_field( $params['location'] ),
        'reg_url'      => esc_url_raw( $params['reg_url'] ?? '' ),
        'description'  => $description_html,
        'loc_tag'      => sanitize_text_field( $params['loc_tag'] ?? '' ),
        'int_tag'      => sanitize_text_field( $params['int_tag'] ?? '' ),
        'submitted_at' => time(),
        'ip'           => $ip,
    ];

    $pending_json = wp_json_encode( $pending );
    $encryption = \BosqueGrande\Core\Plugin::instance()->container()->get( 'encryption' );
    $pending_enc = is_string( $pending_json )
        ? $encryption->encrypt( $pending_json, 'event-suggestion:' . $token )
        : '';
    if ( $pending_enc === '' || $pending_enc === $pending_json ) {
        return new WP_REST_Response( [
            'success' => false,
            'message' => bgev_wording( 'suggest_api_secure_storage', 'Secure suggestion storage is temporarily unavailable. Please contact the organizer.' ),
        ], 503 );
    }
    // Reserve outbound-mail capacity only after the entire suggestion has
    // passed validation. Invalid requests cannot exhaust the shared budget.
    if ( ! bgev_claim_suggestion_mail_budget( 'ip:' . hash( 'sha256', $ip ), 3, HOUR_IN_SECONDS )
        || ! bgev_claim_suggestion_mail_budget( 'email:' . $target, 3, HOUR_IN_SECONDS )
        || ! bgev_claim_suggestion_mail_budget( 'repeat:' . $repeat, 1, 2 * MINUTE_IN_SECONDS )
        || ! bgev_claim_suggestion_mail_budget( 'global', 20, HOUR_IN_SECONDS )
    ) {
        return new WP_REST_Response( [
            'success' => false,
            'message' => bgev_wording( 'suggest_api_too_many', 'Too many event-suggestion requests. Please wait and try again later.' ),
        ], 429 );
    }
    if ( ! set_transient( 'bgev_pending_' . $token, $pending_enc, BGEV_SUGGESTION_VERIFY_HOURS * HOUR_IN_SECONDS ) ) {
        return new WP_REST_Response( [
            'success' => false,
            'message' => bgev_wording( 'suggest_api_save_failed', 'The suggestion could not be saved for verification. Please try again.' ),
        ], 500 );
    }
    // Send verification email
    $sent = bgev_email_verification( $email, $pending['name'], $pending['title'], $token );
    if ( ! $sent ) {
        delete_transient( 'bgev_pending_' . $token );
        return new WP_REST_Response( [ 'success' => false, 'message' => bgev_wording( 'suggest_api_email_failed', 'Could not send verification email. Please contact the site administrator.' ) ], 500 );
    }

    bgev_log( 'Event suggestion received for email verification (token issued).' );
    return new WP_REST_Response( [
        'success' => true,
        'message' => bgev_wording(
            'suggest_verify_notice',
            "We've emailed you a confirmation link. Tap it within {hours} hours to confirm your suggestion.",
            [ 'hours' => (string) BGEV_SUGGESTION_VERIFY_HOURS ]
        ),
    ], 200 );
}

/* Public event suggestion endpoint. */
add_action( 'rest_api_init', function() {
    $args = [
        'methods'  => 'POST',
        'callback' => 'bgev_handle_submission',
        // Public form. A render-time nonce cannot survive full-page
        // caching for anonymous visitors, so admission is open and
        // abuse control lives in the handler: honeypot, minimum fill
        // time, and a per-address & per-IP rate limit.
        'permission_callback' => '__return_true',
    ];
    register_rest_route( 'gathering-tree/v1', '/submit', $args );
} );

add_action( 'template_redirect', function() {
    $is_post = isset( $_POST['bgev_verify_confirm'] );
    $raw_token = $is_post ? $_POST['bgev_verify_confirm'] : ( $_GET['bgev_verify'] ?? '' );
    if ( $raw_token === '' ) return;
    $token = sanitize_text_field( wp_unslash( $raw_token ) );
    if ( ! preg_match( '/^[a-zA-Z0-9]{32}$/', $token ) ) {
        bgev_render_verification_page( false, bgev_wording( 'verification_invalid', 'Invalid verification link.' ) );
        return;
    }

    $token_hash = hash( 'sha256', $token );
    $marker_key = 'bgev_verified_' . $token_hash;
    $completed_post = bgev_verified_suggestion_post_id( get_option( $marker_key, [] ) );
    if ( $completed_post > 0 ) {
        bgev_render_verification_page( true, bgev_wording( 'verification_already_confirmed', 'This event suggestion is already confirmed and safely stored for staff review.' ) );
        return;
    }

    // Link scanners may follow GET URLs automatically. Display a deliberate
    // confirmation first; only POST may create the pending event record.
    if ( ! $is_post ) {
        if ( ! get_transient( 'bgev_pending_' . $token ) ) {
            bgev_render_verification_page( false, bgev_wording( 'verification_expired', 'This verification link has expired or already been used. Please submit your suggestion again.' ) );
            return;
        }
        bgev_render_verification_page(
            false,
            bgev_wording( 'verification_confirm_instruction', 'Select Confirm suggestion to place this event in the staff review queue.' ),
            $token
        );
        return;
    }

    $claim_key = 'bgev_verify_claim_' . $token_hash;
    $claim_owner = \BosqueGrande\Core\OptionLease::acquire( $claim_key, 15 * MINUTE_IN_SECONDS );
    if ( $claim_owner === '' ) {
        bgev_render_verification_page( false, bgev_wording( 'verification_already_processing', 'This verification link is already being processed or has already been used.' ) );
        return;
    }
    register_shutdown_function( static function() use ( $claim_key, $claim_owner ): void {
        \BosqueGrande\Core\OptionLease::release( $claim_key, $claim_owner );
    } );

    $stored_pending = get_transient( 'bgev_pending_' . $token );
    $pending = $stored_pending;
    if ( is_string( $stored_pending ) ) {
        $plain = \BosqueGrande\Core\Plugin::instance()->container()->get( 'encryption' )
            ->decrypt( $stored_pending, 'event-suggestion:' . $token );
        $pending = json_decode( $plain, true );
    }
    if ( ! $pending || ! is_array( $pending ) ) {
        bgev_render_verification_page( false, bgev_wording( 'verification_expired', 'This verification link has expired or already been used. Please submit your suggestion again.' ) );
        return;
    }

    $s         = get_option( BGEV_OPT, [] );
    $test_mode = ! empty( $s['test_mode'] );

    // Suggestions are held locally for office review; no external system
    // receives anything at this stage.
    $start_dt = new DateTime( $pending['start_dt'] );
    $start_dt->setTimezone( wp_timezone() );
    $start_date = $start_dt->format( 'Y-m-d' );
    $start_time = $start_dt->format( 'g:i A' );
    $end_date   = '';
    $end_time   = '';
    if ( $pending['end_dt'] ) {
        $end_dt = new DateTime( $pending['end_dt'] );
        $end_dt->setTimezone( wp_timezone() );
        $end_date = $end_dt->format( 'Y-m-d' );
        $end_time = $end_dt->format( 'g:i A' );
    } else {
        $fallback_end = ( clone $start_dt )->modify( '+1 hour' );
        $end_date = $fallback_end->format( 'Y-m-d' );
        $end_time = $fallback_end->format( 'g:i A' );
    }

    $verification_slug = 'bg-suggestion-' . substr( $token_hash, 0, 32 );
    $marker = get_option( $marker_key, [] );
    $pid = is_array( $marker ) ? (int) ( $marker['post_id'] ?? 0 ) : 0;
    if ( $pid < 1 || get_post_type( $pid ) !== 'bg_event' || get_post_status( $pid ) === 'trash' ) {
        $current_claim = get_option( $claim_key, [] );
        if ( ! is_array( $current_claim )
            || ! hash_equals( (string) ( $current_claim['owner'] ?? '' ), $claim_owner )
        ) {
            bgev_render_verification_page( false, bgev_wording( 'verification_other_request', 'This verification is being completed by another request. Please check the link again shortly.' ) );
            return;
        }
        if ( ! is_array( $marker ) || ( $marker['state'] ?? '' ) === '' ) {
            $reserved = [ 'post_id' => 0, 'state' => 'reserved', 'at' => time() ];
            if ( ! add_option( $marker_key, $reserved, '', false ) ) {
                bgev_render_verification_page( false, bgev_wording( 'verification_retry_processing', 'This verification link is already being processed. Please try again shortly.' ) );
                return;
            }
        }
        global $wpdb;
        $pid = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'bg_event'
               AND (post_name = %s OR post_name LIKE %s)
               AND post_status <> 'trash'
             ORDER BY ID ASC LIMIT 1",
            $verification_slug,
            $wpdb->esc_like( $verification_slug ) . '-%'
        ) );
    }
    if ( $pid < 1 || get_post_type( $pid ) !== 'bg_event' || get_post_status( $pid ) === 'trash' ) {
        $pid = wp_insert_post( [
            'post_type'    => 'bg_event',
            'post_title'   => $pending['title'],
            'post_content' => $pending['description'],
            'post_status'  => 'pending',
            'post_name'    => $verification_slug,
        ] );

        if ( is_wp_error( $pid ) || ! $pid ) {
            bgev_render_verification_page( false, bgev_wording( 'verification_create_failed', 'Could not create event record. Please contact the site administrator.' ) );
            return;
        }
    }
    $building = [ 'post_id' => (int) $pid, 'state' => 'building', 'at' => time() ];
    update_option( $marker_key, $building, false );
    $stored_marker = get_option( $marker_key, [] );
    if ( ! is_array( $stored_marker ) || (int) ( $stored_marker['post_id'] ?? 0 ) !== (int) $pid ) {
        bgev_render_verification_page( false, bgev_wording( 'verification_finalize_failed', 'The event is stored for review, but its verification record could not be finalized. Please contact the site administrator.' ) );
        return;
    }

    $encryption = \BosqueGrande\Core\Plugin::instance()->container()->get( 'encryption' );
    $submitter_name = $encryption->encrypt(
        sanitize_text_field( $pending['name'] ),
        'event-meta:' . (int) $pid . ':_bg_submitter_name'
    );
    $submitter_email = $encryption->encrypt(
        sanitize_email( $pending['email'] ),
        'event-meta:' . (int) $pid . ':_bg_submitter_email'
    );
    $submitter_ip = $encryption->encrypt(
        sanitize_text_field( $pending['ip'] ),
        'event-meta:' . (int) $pid . ':_bg_submitter_ip'
    );
    $meta_ok = $submitter_name !== '' && $submitter_email !== '' && $submitter_ip !== ''
        && bgev_store_suggestion_meta( $pid, '_bg_start_date', $start_date )
        && bgev_store_suggestion_meta( $pid, '_bg_start_time', $start_time )
        && bgev_store_suggestion_meta( $pid, '_bg_submitter_name', $submitter_name )
        && bgev_store_suggestion_meta( $pid, '_bg_submitter_email', $submitter_email )
        && bgev_store_suggestion_meta( $pid, '_bg_submitter_ip', $submitter_ip )
        && bgev_store_suggestion_meta( $pid, '_bg_location_text', sanitize_text_field( $pending['location'] ) );
    if ( $meta_ok && $end_date ) {
        $meta_ok = bgev_store_suggestion_meta( $pid, '_bg_end_date', $end_date );
    }
    if ( $meta_ok && $end_time ) {
        $meta_ok = bgev_store_suggestion_meta( $pid, '_bg_end_time', $end_time );
    }
    if ( $meta_ok && $pending['reg_url'] ) {
        $meta_ok = bgev_store_suggestion_meta( $pid, '_bg_reg_url', esc_url_raw( $pending['reg_url'] ) );
    }
    if ( ! $meta_ok ) {
        bgev_render_verification_page( false, bgev_wording( 'verification_secure_record_failed', 'The event was verified, but its secure review record could not be completed. Please contact the site administrator.' ) );
        return;
    }

    // Tentative tags from the suggester's selection; the office can revise on approval.
    $location_ids = [];
    $interest_ids = [];
    if ( $pending['loc_tag'] ) {
        $t = get_term_by( 'slug', $pending['loc_tag'], bg_location_taxonomy() );
        if ( $t && ! is_wp_error( $t ) ) $location_ids[] = (int) $t->term_id;
    }
    if ( $pending['int_tag'] ) {
        $t = get_term_by( 'slug', $pending['int_tag'], 'bg_tag' );
        if ( $t && ! is_wp_error( $t ) ) $interest_ids[] = (int) $t->term_id;
    }
    if ( $location_ids ) wp_set_object_terms( $pid, $location_ids, bg_location_taxonomy(), false );
    if ( $interest_ids ) wp_set_object_terms( $pid, $interest_ids, 'bg_tag', false );

    $current_claim = get_option( $claim_key, [] );
    if ( ! is_array( $current_claim )
        || ! hash_equals( (string) ( $current_claim['owner'] ?? '' ), $claim_owner )
    ) {
        bgev_render_verification_page( false, bgev_wording( 'verification_other_request', 'This verification is being completed by another request. Please check the link again shortly.' ) );
        return;
    }
    $complete_marker = [ 'post_id' => (int) $pid, 'state' => 'complete', 'at' => time() ];
    update_option( $marker_key, $complete_marker, false );
    if ( bgev_verified_suggestion_post_id( get_option( $marker_key, [] ) ) !== (int) $pid ) {
        bgev_render_verification_page( false, bgev_wording( 'verification_finalize_failed', 'The event is stored for review, but its verification record could not be finalized. Please contact the site administrator.' ) );
        return;
    }
    delete_transient( 'bgev_pending_' . $token );
    if ( ! wp_next_scheduled( 'bgev_cleanup_verification_marker', [ $marker_key ] ) ) {
        wp_schedule_single_event( time() + 30 * DAY_IN_SECONDS, 'bgev_cleanup_verification_marker', [ $marker_key ] );
    }

    // 3. Notify designated contact
    $admin_notified = bgev_email_admin_notification( $pending, $pid, $test_mode );

    bgev_log( "Verified submission: '{$pending['title']}' → WP #$pid" . ( $test_mode ? ' [test mode]' : '' ) );
    if ( ! $admin_notified ) {
        bgev_log( "Verified submission WP #$pid was saved, but the administrator notification was refused by the mailer." );
        bgev_render_verification_page( true, bgev_wording( 'verification_staff_email_failed', 'Your suggestion is confirmed and safely stored for review. The staff notification email could not be sent, but the event remains visible in the WordPress pending-events queue.' ) );
        return;
    }
    bgev_render_verification_page( true, bg_str( 'suggest_thanks_notice' ) );
} );

add_action( 'template_redirect', function() {
    if ( ! is_singular( 'bg_event' ) ) return;
    ob_start( function( $h ) {
        $h = preg_replace( '/(<[^>]*>)?\s*You should add MEC Single Sidebar Items[^<]*(<\/[^>]*>)?/i', '', $h );
        return preg_replace( '/(<[^>]*>)?\s*the event is finished\.?\s*(<\/[^>]*>)?/i', '', $h );
    } );
}, 1 );
