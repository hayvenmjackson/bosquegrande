<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * Background-job facade over WP-Cron. Callers enqueue a named job with
 * scalar-safe arguments and register its handler. All BosqueGrande jobs
 * share the BG_HOOK prefix so clearAll() can find them.
 */
final class Jobs
{
    private const HOOK = 'bg_job';

    public function __construct()
    {
        add_action(self::HOOK, [$this, 'dispatch'], 10, 2);
    }

    /** @var array<string, callable> */
    private static array $handlers = [];

    public function register(string $name, callable $handler): void
    {
        self::$handlers[$name] = $handler;
    }

    /**
     * Queue a one-off job. Runs on the next cron tick after $delay
     * seconds. Args must be scalar-safe (they serialize into cron).
     *
     * @param array<string, mixed> $args
     */
    public function enqueue(string $name, array $args = [], int $delay = 0): bool
    {
        $result = wp_schedule_single_event(time() + max(0, $delay), self::HOOK, [$name, $args], true);
        if (is_wp_error($result) || $result === false) {
            (new Logger())->error('jobs', 'Background job could not be scheduled.', [
                'job' => $name,
                'reason' => is_wp_error($result) ? $result->get_error_message() : 'WordPress refused the event',
            ]);
            return false;
        }
        return true;
    }

    /** @param array<string, mixed> $args */
    public function dispatch(string $name, array $args = []): void
    {
        if (!isset(self::$handlers[$name])) {
            (new Logger())->warn('jobs', "No handler registered for job '$name'.");
            return;
        }
        try {
            (self::$handlers[$name])($args);
        } catch (\Throwable $e) {
            (new Logger())->error('jobs', "Job '$name' failed: " . $e->getMessage());
        }
    }

    public function clearAll(): void
    {
        wp_unschedule_hook(self::HOOK);
    }
}
