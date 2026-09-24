<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Registration;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\MailText;

if (!defined('ABSPATH')) exit;

/**
 * One canonical plain-text registration confirmation for both unpaid
 * and Stripe-settled registrations.
 */
final class ConfirmationEmail
{
    public static function subject(int $eventId): string
    {
        return MailText::line(RegistrationWording::format(
            'registration_email_subject',
            'You are registered: {event}',
            ['event' => self::eventTitle($eventId)]
        ));
    }

    public static function body(
        Container $container,
        int $eventId,
        string $first,
        string $tier,
        string $email,
        int $constituentId,
        bool $paymentReceived = false
    ): string {
        $first = MailText::line($first);
        $title = self::eventTitle($eventId);
        $tierLabel = MailText::line((string) (Tiers::all()[$tier]['label'] ?? $tier));
        $intro = $paymentReceived
            ? RegistrationWording::format(
                'registration_email_paid_intro',
                "Your payment went through and you're registered for {event} ({tier}).",
                ['event' => $title, 'tier' => $tierLabel]
            )
            : RegistrationWording::format(
                'registration_email_unpaid_intro',
                "You're registered for {event} ({tier}).",
                ['event' => $title, 'tier' => $tierLabel]
            );

        $greeting = RegistrationWording::format(
            'registration_email_greeting',
            'Hi {first},',
            ['first' => $first]
        );
        $body = MailText::line($greeting) . "\n\n" . MailText::line($intro) . "\n";
        $details = self::eventDetails($eventId);
        if ($details['date'] !== '') {
            $label = MailText::line(RegistrationWording::text('registration_email_date_label', 'Date'));
            $body .= "\n{$label}: {$details['date']}";
        }
        if ($details['time'] !== '') {
            $label = MailText::line(RegistrationWording::text('registration_email_time_label', 'Time'));
            $body .= "\n{$label}: {$details['time']}";
        }
        if ($details['description'] !== '') {
            $label = MailText::line(RegistrationWording::text(
                'registration_email_description_label',
                'Event description'
            ));
            $body .= "\n\n{$label}:\n{$details['description']}";
        }
        // Contact details default to the organization settings. The wording
        // keys override them for this email only; filters allow per-event
        // contacts.
        $contactName = MailText::line((string) apply_filters(
            'bg_confirmation_contact_name',
            RegistrationWording::text('registration_email_contact_name', '') ?: \BosqueGrande\Core\Organization::name(),
            $eventId
        ));
        $contactEmail = sanitize_email((string) apply_filters(
            'bg_confirmation_contact_email',
            sanitize_email(RegistrationWording::text('registration_email_contact_email', '')) ?: \BosqueGrande\Core\Organization::contactEmail(),
            $eventId
        ));
        $contactPhone = MailText::line((string) apply_filters(
            'bg_confirmation_contact_phone',
            RegistrationWording::text('registration_email_contact_phone', '') ?: \BosqueGrande\Core\Organization::contactPhone(),
            $eventId
        ));

        $contactIntro = MailText::line(RegistrationWording::format(
            'registration_email_contact_intro',
            'If you have any questions, please contact {contact}:',
            ['contact' => $contactName]
        ));
        $emailLabel = MailText::line(RegistrationWording::text(
            'registration_email_contact_email_label',
            'Email'
        ));
        $phoneLabel = MailText::line(RegistrationWording::text(
            'registration_email_contact_phone_label',
            'Phone'
        ));
        $closing = MailText::line(RegistrationWording::text(
            'registration_email_closing',
            'We look forward to seeing you!'
        ));
        if (is_email($contactEmail) || $contactPhone !== '') {
            $body .= "\n\n{$contactIntro}";
            if (is_email($contactEmail)) $body .= "\n{$emailLabel}: {$contactEmail}";
            if ($contactPhone !== '') $body .= "\n{$phoneLabel}: {$contactPhone}";
        }
        $body .= "\n\n{$closing}\n";
        $body .= RegistrationModule::saveInfoParagraph($container, $email, $constituentId);
        return $body;
    }

    /**
     * @return array{date: string, time: string, description: string}
     */
    public static function eventDetails(int $eventId): array
    {
        $post = get_post($eventId);
        $start = (string) get_post_meta($eventId, '_bg_start_date', true);
        $end = (string) get_post_meta($eventId, '_bg_end_date', true);
        $date = self::formatDate($start);
        if ($end !== '' && $end !== $start) {
            $formattedEnd = self::formatDate($end);
            if ($formattedEnd !== '') $date .= ' – ' . $formattedEnd;
        }

        $time = function_exists('bgev_time_range')
            ? (string) \bgev_time_range($eventId)
            : (string) get_post_meta($eventId, '_bg_start_time', true);

        return [
            'date' => $date,
            'time' => MailText::line($time),
            'description' => $post ? MailText::block((string) $post->post_content) : '',
        ];
    }

    private static function eventTitle(int $eventId): string
    {
        $title = MailText::line((string) get_the_title($eventId));
        return $title !== '' ? $title : MailText::line(RegistrationWording::text(
            'registration_email_event_fallback',
            'your event'
        ));
    }

    private static function formatDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        $timezone = wp_timezone();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timezone);
        if (!$date) return MailText::line($raw);
        $format = (string) get_option('date_format', 'F j, Y');
        return wp_date($format !== '' ? $format : 'F j, Y', $date->getTimestamp(), $timezone);
    }
}
