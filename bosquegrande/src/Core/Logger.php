<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * Structured activity log in wp_bg_jobs_log: the readable record of what
 * the system did. Entries older than a year are pruned daily. Context
 * arrays are JSON-encoded; never log secrets or demographic answers.
 */
final class Logger
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARN = 'warn';
    public const LEVEL_ERROR = 'error';
    public const PRUNE_HOOK = 'bg_log_prune';
    private const RETENTION_DAYS = 365;

    /** @param array<string, mixed> $context */
    public function info(string $channel, string $message, array $context = []): void
    {
        $this->write(self::LEVEL_INFO, $channel, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warn(string $channel, string $message, array $context = []): void
    {
        $this->write(self::LEVEL_WARN, $channel, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $channel, string $message, array $context = []): void
    {
        $this->write(self::LEVEL_ERROR, $channel, $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $channel, string $message, array $context): void
    {
        global $wpdb;
        $json = wp_json_encode($context);
        $suppressed = $wpdb->suppress_errors(true);
        $ok = $wpdb->insert($wpdb->prefix . 'bg_jobs_log', [
            'level' => $level,
            'channel' => $channel,
            'message' => $message,
            'context' => $json,
            'created_at' => current_time('mysql', true),
        ], ['%s', '%s', '%s', '%s', '%s']);
        $wpdb->suppress_errors($suppressed);
        // Before the table exists (first activation) or if the insert
        // fails, the PHP error log still gets the entry.
        if ($ok === false) {
            error_log(sprintf('[BosqueGrande %s/%s] %s %s', $level, $channel, $message, (string) $json));
        }
    }

    /** Delete entries older than the retention window. Runs daily. */
    public function prune(int $days = self::RETENTION_DAYS): void
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $days) * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_jobs_log WHERE created_at < %s LIMIT 5000",
            $cutoff
        ));
    }

    /** @return array<int, object> Most recent entries, newest first. */
    public function recent(int $limit = 50): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bg_jobs_log';
        $limit = max(1, min(200, $limit));
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit)
        ) ?: [];
    }
}
