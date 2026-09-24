<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Intelligence;

use BosqueGrande\Core\Logger;
use BosqueGrande\Core\OptionLease;

if (!defined('ABSPATH')) exit;

/**
 * The internal support desk behind the assistant.
 *
 * The assistant is grounded on reference material: the shipped staff
 * reference for staff questions, and whatever the organization adds through
 * bg_assistant_reference (the Floating Assistant companion's training info)
 * for either audience. When that reference and the site-safe facts do not
 * answer a question, the assistant
 * can notify the site's designated administrator instead of guessing. The
 * default recipient is the calendar's designated contact, falling back to
 * WordPress's Administration Email Address; deployments can override it with
 * bg_support_emails. No outside vendor contact, phone
 * number, or support-hours promise is built into the product.
 *
 * Escalation email is deliberately capped: per-visitor 3/hour and 25/day
 * sitewide, so a public chat button cannot flood the administrator's inbox.
 */
final class SupportDesk
{
    public const ESCALATE_MARKER = '[ESCALATE]';
    private const DAILY_CAP = 25;
    private const OWN_REFERENCE_CAP = 16000;

    public function __construct(private Logger $logger)
    {
    }

    /** @return list<string> */
    public static function emails(): array
    {
        $calendar = get_option('bg_calendar_settings', []);
        $designated = is_array($calendar)
            ? sanitize_email((string) ($calendar['designated_contact_email'] ?? ''))
            : '';
        $adminEmail = sanitize_email((string) get_option('admin_email', ''));
        $default = is_email($designated) ? $designated : $adminEmail;
        $defaults = is_email($default) ? [$default] : [];
        $emails = array_map('sanitize_email', (array) apply_filters('bg_support_emails', $defaults));
        return array_values(array_filter($emails, 'is_email'));
    }

    public static function docsUrl(): string
    {
        return trim((string) apply_filters('bg_assistant_docs_url', ''));
    }

    /** The handbook text shipped with the plugin, capped for prompt size. */
    public static function docs(): string
    {
        static $docs = null;
        if ($docs === null) {
            $path = BG_DIR . 'assets/handbook.txt';
            $docs = is_readable($path) ? substr((string) file_get_contents($path), 0, 26000) : '';
            $docs = (string) apply_filters('bg_assistant_docs', $docs);
        }
        return $docs;
    }

    /**
     * Reference material for one question, ready to append to the prompt.
     * Staff questions get the shipped staff reference. Visitor questions
     * never do, since it describes internal screens and roles. Either
     * audience also gets the organization's own material, chosen for the
     * question by whoever answers bg_assistant_reference (audience is
     * 'staff' or 'visitor').
     */
    public static function reference(string $channel, string $question): string
    {
        $audience = $channel === 'admin' ? 'staff' : 'visitor';
        $parts = [];
        $own = trim((string) apply_filters('bg_assistant_reference', '', $audience, $question));
        if ($own !== '') $parts[] = "ORGANIZATION REFERENCE:\n" . substr($own, 0, self::OWN_REFERENCE_CAP);
        if ($audience === 'staff') {
            $docs = self::docs();
            if ($docs !== '') $parts[] = "STAFF REFERENCE:\n" . $docs;
        }
        return $parts ? "\n\n" . implode("\n\n", $parts) : '';
    }

    /**
     * The reply a person sees after an attempted human escalation. Visitors
     * cannot be answered by email (the question arrives without their
     * address), so they are pointed at the organization's own contact
     * instead of an internal support process.
     */
    public static function escalationReply(bool $sent = true, string $channel = 'admin'): string
    {
        if ($channel !== 'admin') {
            $email = \BosqueGrande\Core\Organization::publicEmail();
            $contact = $email !== '' ? 'email us at ' . $email : 'contact us directly';
            $text = $sent
                ? bg_str('assistant_visitor_escalated', 'I don\'t have a reliable answer to that, so I\'ve let our team know it came up. For a reply, {contact}.')
                : bg_str('assistant_visitor_unanswered', 'I don\'t have a reliable answer to that. For help, {contact}.');
            return str_replace('{contact}', $contact, $text);
        }
        return $sent
            ? 'I want to get this one right, so I\'ve sent your question to this site\'s designated administrator. Please follow your organization\'s internal support process for any follow-up.'
            : 'I couldn\'t send this question automatically. Please contact this site\'s designated administrator through your organization\'s internal support process.';
    }

    /**
     * Email the question to the designated administrator. Returns whether a message was
     * actually sent (rate caps can decline; the person still gets the
     * escalation reply either way, and declined sends are logged).
     */
    public function escalate(string $question, string $channel, string $pageUrl = '', string $clientKey = ''): bool
    {
        $emails = self::emails();
        if (!$emails) return false;

        // Reserve both limits under one owner-bound claim so parallel public
        // requests cannot all observe the same counters and overrun the mail
        // budget. Storage failure is a safe refusal, not an uncapped send.
        $budgetOwner = OptionLease::acquire('bg_escalation_budget_lock', 10);
        if ($budgetOwner === '') return false;
        try {
            $visitorKey = $channel !== 'admin' && $clientKey !== ''
                ? 'bg_esc_' . md5($clientKey)
                : '';
            $visitorCount = $visitorKey !== '' ? (int) get_transient($visitorKey) : 0;
            if ($visitorCount >= 3) {
                $this->logger->warn('intelligence', 'Escalation email declined: per-visitor cap reached.');
                return false;
            }

            $dayKey = 'bg_escalations_' . gmdate('Ymd');
            $today = (int) get_option($dayKey, 0);
            if ($today >= self::DAILY_CAP) {
                $this->logger->warn('intelligence', 'Escalation email declined: daily cap reached.');
                return false;
            }
            if (!update_option($dayKey, $today + 1, false)) return false;
            if ($visitorKey !== ''
                && !set_transient($visitorKey, $visitorCount + 1, HOUR_IN_SECONDS)
            ) return false;
        } finally {
            OptionLease::release('bg_escalation_budget_lock', $budgetOwner);
        }

        $subject = '[BosqueGrande] Assistant escalation: ' . get_bloginfo('name');
        $body = "The site assistant could not answer a question and escalated it.\n\n"
            . 'Site: ' . home_url() . "\n"
            . 'Channel: ' . ($channel === 'admin' ? 'admin assistant' : 'visitor chat') . "\n"
            . ($pageUrl !== '' ? 'Page: ' . $pageUrl . "\n" : '')
            . 'Time: ' . gmdate('Y-m-d H:i') . " UTC\n\n"
            . "Question:\n" . $question . "\n";

        $sent = wp_mail($emails, $subject, $body);
        $this->logger->info('intelligence', 'Assistant escalation ' . ($sent ? 'emailed to the designated administrator.' : 'email failed.'), [
            'channel' => $channel,
        ]);
        return (bool) $sent;
    }
}
