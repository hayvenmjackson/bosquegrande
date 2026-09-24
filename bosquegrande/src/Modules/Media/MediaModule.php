<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Media;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;

if (!defined('ABSPATH')) exit;

/**
 * Deterministic event-photo assignment with manual overrides. Attached
 * images are ranked by resolution; staff can choose or remove any slot and
 * lock the result against automatic reselection. The Photos screen can repair
 * events that are missing a photo in bulk. The legacy annotation job remains
 * registered as a no-op so previously queued work completes safely.
 */
final class MediaModule implements ModuleInterface
{
    public function name(): string
    {
        return 'media';
    }

    public function label(): string
    {
        return 'Media and Photos';
    }

    public function activate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        $container->set('media.selector', fn(Container $c) => new PhotoSelector(
            $c->get('logger'),
        ));

        $jobs = $container->get('jobs');
        $jobs->register('annotate_image', function (array $args) use ($container) {
            $id = (int) ($args['attachment_id'] ?? 0);
            if ($id) $container->get('media.selector')->annotate($id);
        });
        $jobs->register('assign_photos', function (array $args) use ($container) {
            $id = (int) ($args['event_post_id'] ?? 0);
            if ($id) $container->get('media.selector')->assign($id);
        });

        // New published events without photos queue an assignment.
        add_action('save_post_bg_event', function (int $postId, \WP_Post $post) use ($container) {
            if ($post->post_status !== 'publish') return;
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
            $photos = get_post_meta($postId, '_bg_photos', true);
            if (is_array($photos) && (!empty($photos['primary']) || !empty($photos['override']))) return;
            $container->get('jobs')->enqueue('assign_photos', ['event_post_id' => $postId], 30);
        }, 20, 2);

        if (is_admin()) {
            $this->registerMetaBox($container);
            $this->registerPhotosPage($container);
        }
    }

    public function deactivate(Container $container): void
    {
    }

    /* ---------------------------------------------------------------
     * Event edit screen meta box
     * --------------------------------------------------------------- */

    private function registerMetaBox(Container $container): void
    {
        add_action('add_meta_boxes_bg_event', function () {
            add_meta_box('bg_photos', 'Photos', [$this, 'renderMetaBox'], 'bg_event', 'side', 'default');
        });

        add_action('save_post_bg_event', function (int $postId) {
            if (!isset($_POST['bg_photos_nonce']) || !wp_verify_nonce((string) $_POST['bg_photos_nonce'], 'bg_photos')) return;
            if (!current_user_can('edit_post', $postId)) return;

            $primary = isset($_POST['bg_photo_primary']) ? (int) $_POST['bg_photo_primary'] : 0;
            $sec1 = isset($_POST['bg_photo_sec1']) ? (int) $_POST['bg_photo_sec1'] : 0;
            $sec2 = isset($_POST['bg_photo_sec2']) ? (int) $_POST['bg_photo_sec2'] : 0;
            $override = !empty($_POST['bg_photo_override']) ? 1 : 0;

            $existing = get_post_meta($postId, '_bg_photos', true);
            $existing = is_array($existing) ? $existing : ['primary' => 0, 'secondary' => [], 'override' => 0];
            $changed = $primary !== (int) ($existing['primary'] ?? 0)
                || [$sec1, $sec2] !== array_pad(array_map('intval', (array) ($existing['secondary'] ?? [])), 2, 0);

            update_post_meta($postId, '_bg_photos', [
                'primary' => $primary,
                'secondary' => array_values(array_filter([$sec1, $sec2])),
                // A human edit locks the choice even if the box was not ticked.
                'override' => ($override || $changed) ? 1 : (int) ($existing['override'] ?? 0),
            ]);
            if ($primary) {
                set_post_thumbnail($postId, $primary);
            } else {
                // Removing the primary removes the calendar thumbnail too.
                delete_post_thumbnail($postId);
            }
        }, 10);

        add_action('admin_post_bg_reassign_photos', function () use ($container) {
            $postId = isset($_POST['bg_reassign_post']) ? (int) $_POST['bg_reassign_post'] : 0;
            if (!$postId || !current_user_can('edit_post', $postId)) wp_die('Not allowed.');
            check_admin_referer('bg_reassign_' . $postId, 'bg_reassign_nonce');
            $photos = get_post_meta($postId, '_bg_photos', true);
            if (is_array($photos)) {
                $photos['override'] = 0;
                update_post_meta($postId, '_bg_photos', $photos);
            }
            $container->get('media.selector')->assign($postId);
            wp_safe_redirect(get_edit_post_link($postId, 'raw'));
            exit;
        });
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        $photos = get_post_meta($post->ID, '_bg_photos', true);
        $photos = is_array($photos) ? $photos : ['primary' => 0, 'secondary' => [], 'override' => 0];
        $sec = array_pad(array_map('intval', (array) $photos['secondary']), 2, 0);
        wp_nonce_field('bg_photos', 'bg_photos_nonce');
        wp_enqueue_media();

        $slot = function (string $label, string $field, int $id) {
            $thumb = $id ? wp_get_attachment_image($id, [120, 90]) : '<em>None</em>';
            echo '<p style="margin-bottom:4px"><strong>' . esc_html($label) . '</strong></p>'
                . '<div class="bg-photo-slot" data-field="' . esc_attr($field) . '">'
                . '<span class="bg-photo-thumb">' . $thumb . '</span><br>'
                . '<input type="hidden" name="' . esc_attr($field) . '" value="' . (int) $id . '">'
                . '<button type="button" class="button bg-pick">Choose photo</button> '
                . '<button type="button" class="button-link-delete bg-clear" style="margin-left:4px">Remove</button>'
                . '</div>';
        };
        $slot('Primary (featured image)', 'bg_photo_primary', (int) $photos['primary']);
        $slot('Secondary 1', 'bg_photo_sec1', $sec[0]);
        $slot('Secondary 2', 'bg_photo_sec2', $sec[1]);

        // One-click picks from the photos imported with this event.
        $attached = get_posts([
            'post_type' => 'attachment', 'post_status' => 'inherit',
            'post_mime_type' => 'image', 'post_parent' => $post->ID,
            'posts_per_page' => 8, 'fields' => 'ids',
        ]);
        if ($attached) {
            echo '<p style="margin:12px 0 4px"><strong>Imported with this event</strong><br>'
                . '<span class="description">Click one to make it the featured photo.</span></p>'
                . '<div class="bg-attached-strip">';
            foreach ($attached as $attId) {
                echo '<a href="#" class="bg-use-attached" data-id="' . (int) $attId . '" '
                    . 'data-url="' . esc_url((string) wp_get_attachment_image_url((int) $attId, 'thumbnail')) . '">'
                    . wp_get_attachment_image((int) $attId, [72, 54])
                    . '</a>';
            }
            echo '</div>';
        }

        echo '<p style="margin-top:10px"><label><input type="checkbox" name="bg_photo_override" value="1" '
            . checked(!empty($photos['override']), true, false)
            . '> Lock photos (prevent automatic reselection)</label></p>';

        echo wp_nonce_field('bg_reassign_' . $post->ID, 'bg_reassign_nonce', true, false)
            . '<input type="hidden" name="bg_reassign_post" value="' . (int) $post->ID . '">'
            . '<p><button type="submit" name="action" value="bg_reassign_photos" class="button" '
            . 'formaction="' . esc_url(admin_url('admin-post.php')) . '" formmethod="post" formnovalidate>'
            . 'Suggest photos again</button></p>';
        echo '<p class="description">Changes take effect when you click Update.</p>';
        ?>
        <script>
        jQuery(function($){
          function setSlot(slot, id, url){
            slot.find('input').val(id);
            slot.find('.bg-photo-thumb').html(
              id ? '<img src="' + url + '" style="max-width:120px;height:auto">' : '<em>None</em>'
            );
          }
          $('.bg-pick').on('click', function(){
            var slot = $(this).closest('.bg-photo-slot');
            var frame = wp.media({title: 'Choose photo', multiple: false, library: {type: 'image'}});
            frame.on('select', function(){
              var a = frame.state().get('selection').first().toJSON();
              var url = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
              setSlot(slot, a.id, url);
            });
            frame.open();
          });
          $('.bg-clear').on('click', function(e){
            e.preventDefault();
            setSlot($(this).closest('.bg-photo-slot'), 0, '');
          });
          $('.bg-use-attached').on('click', function(e){
            e.preventDefault();
            var slot = $('.bg-photo-slot[data-field="bg_photo_primary"]');
            setSlot(slot, $(this).data('id'), $(this).data('url'));
          });
        });
        </script>
        <style>
        .bg-attached-strip{display:flex;flex-wrap:wrap;gap:6px}
        .bg-attached-strip a{display:inline-block;border:2px solid transparent;border-radius:6px;overflow:hidden;line-height:0}
        .bg-attached-strip a:hover{border-color:#2E7D4F}
        </style>
        <?php
    }

    /* ---------------------------------------------------------------
     * Photos admin page: bulk repair for events missing a photo
     * --------------------------------------------------------------- */

    private function registerPhotosPage(Container $container): void
    {
        add_action('admin_menu', function () {
            add_submenu_page('bosquegrande', 'Event Photos', 'Photos',
                'edit_posts', 'bosquegrande-photos', [$this, 'renderPhotosPage']);
        }, 38);

        add_action('admin_post_bg_photos_fill', function () use ($container) {
            if (!current_user_can('edit_posts')) wp_die('Not allowed.');
            check_admin_referer('bg_photos_fill');
            $ids = $this->eventsMissingPhotos();
            $jobs = $container->get('jobs');
            $queued = 0;
            foreach ($ids as $i => $id) {
                // Staggered so vision annotation never bursts the provider.
                if ($jobs->enqueue('assign_photos', ['event_post_id' => $id], $i * 10)) {
                    $queued++;
                }
            }
            wp_safe_redirect(add_query_arg(
                ['page' => 'bosquegrande-photos', 'queued' => $queued, 'failed' => count($ids) - $queued],
                admin_url('admin.php')
            ));
            exit;
        });
    }

    /** Published events with no thumbnail and no human lock. @return int[] */
    private function eventsMissingPhotos(): array
    {
        $q = new \WP_Query([
            'post_type'      => 'bg_event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [[
                'key'     => '_thumbnail_id',
                'compare' => 'NOT EXISTS',
            ]],
        ]);
        $ids = [];
        foreach ($q->posts as $id) {
            $photos = get_post_meta((int) $id, '_bg_photos', true);
            if (is_array($photos) && !empty($photos['override'])) {
                continue; // A human chose "no photo"; respect it.
            }
            $ids[] = (int) $id;
        }
        return $ids;
    }

    public function renderPhotosPage(): void
    {
        if (!current_user_can('edit_posts')) return;
        $missing = $this->eventsMissingPhotos();

        if (isset($_GET['queued'])) {
            $failed = max(0, (int) ($_GET['failed'] ?? 0));
            printf(
                '<div class="notice %s is-dismissible"><p>%s</p></div>',
                $failed > 0 ? 'notice-warning' : 'notice-success',
                esc_html(sprintf(
                    'Queued photo assignment for %d event(s)%s. Photos appear as the background jobs run; refresh in a few minutes.',
                    (int) $_GET['queued'],
                    $failed > 0 ? sprintf('; %d could not be scheduled', $failed) : ''
                ))
            );
        }

        echo '<div class="wrap"><h1>Event Photos</h1>';
        echo '<p>Every event on the calendar should have a featured photo. Imported events '
            . 'carry their photos with them; this page assigns one automatically wherever a photo is missing. '
            . 'To change or remove a photo on a single event, open the event and use the Photos box.</p>';

        if ($missing === []) {
            echo '<p><strong>All published events have a photo.</strong></p></div>';
            return;
        }

        printf('<p><strong>%d</strong> published event(s) are missing a photo.</p>', count($missing));

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:1.2em">';
        wp_nonce_field('bg_photos_fill');
        echo '<input type="hidden" name="action" value="bg_photos_fill" />';
        submit_button('Assign photos to all of them', 'primary', 'submit', false);
        echo '<p class="description">Events with their own imported photo get it directly. Events with several '
            . 'photos or none get a smart pick. A human can change or remove any result afterward.</p>';
        echo '</form>';

        echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Event</th><th>Date</th></tr></thead><tbody>';
        foreach (array_slice($missing, 0, 100) as $id) {
            printf(
                '<tr><td><a href="%s">%s</a></td><td>%s</td></tr>',
                esc_url((string) get_edit_post_link($id)),
                esc_html(get_the_title($id) !== '' ? get_the_title($id) : ('#' . $id)),
                esc_html((string) get_post_meta($id, '_bg_start_date', true))
            );
        }
        echo '</tbody></table>';
        if (count($missing) > 100) {
            printf('<p class="description">Showing the first 100 of %d.</p>', count($missing));
        }
        echo '</div>';
    }
}
