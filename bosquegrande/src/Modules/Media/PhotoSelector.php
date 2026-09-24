<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Media;

use BosqueGrande\Core\Logger;

if (!defined('ABSPATH')) exit;

/**
 * Rules-based photo selection. Images attached to an event are ranked by
 * resolution; the best becomes the featured image and the next two become
 * secondary images. A staff override locks the choice. Events with no
 * attached images are left unchanged, and unrelated media is never selected.
 */
final class PhotoSelector
{
    public function __construct(
        private Logger $logger,
    ) {
    }

    /**
     * Kept as a safe no-op so queued annotate jobs from any path
     * always resolve; rules-based selection needs no annotations.
     */
    public function annotate(int $attachmentId): void
    {
    }

    /** Pick and set photos for one event from its attached images. */
    public function assign(int $eventId): void
    {
        $photos = get_post_meta($eventId, '_bg_photos', true);
        $photos = is_array($photos) ? $photos : ['primary' => 0, 'secondary' => [], 'override' => 0];
        if (!empty($photos['override'])) {
            return; // A human locked the choice; it is never revisited.
        }

        $attached = get_posts([
            'post_parent' => $eventId,
            'post_type' => 'attachment',
            'post_status' => 'inherit', // Attachments are never 'publish'; without this the query is empty on a live site.
            'post_mime_type' => 'image',
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
        ]);
        if (!$attached) {
            $this->logger->info('media', 'No attached images; photo left for a human.', ['event' => $eventId]);
            return;
        }

        // Highest resolution wins; ties break toward the most recent.
        $ranked = [];
        foreach (array_values($attached) as $i => $attId) {
            $meta = wp_get_attachment_metadata((int) $attId);
            $area = is_array($meta) ? ((int) ($meta['width'] ?? 0)) * ((int) ($meta['height'] ?? 0)) : 0;
            $ranked[] = ['id' => (int) $attId, 'area' => $area, 'recency' => -$i];
        }
        usort($ranked, static fn($a, $b) => [$b['area'], $b['recency']] <=> [$a['area'], $a['recency']]);

        $primary = $ranked[0]['id'];
        $secondary = array_map(static fn($r) => $r['id'], array_slice($ranked, 1, 2));
        update_post_meta($eventId, '_bg_photos', [
            'primary' => $primary,
            'secondary' => $secondary,
            'override' => (int) ($photos['override'] ?? 0),
        ]);
        set_post_thumbnail($eventId, $primary);
        if (function_exists('wp_get_attachment_url')) {
            update_post_meta($eventId, '_bg_image', (string) wp_get_attachment_url($primary));
        }
        $this->logger->info('media', 'Photos assigned from attached images.', [
            'event' => $eventId, 'primary' => $primary, 'secondary' => $secondary,
        ]);
    }

}
