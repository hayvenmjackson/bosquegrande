<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Imports;

use BosqueGrande\Modules\Registration\DemographicFields;
use BosqueGrande\Modules\Registration\PrefillResolver;
use BosqueGrande\Modules\Registration\SurveyFields;

if (!defined('ABSPATH')) exit;

/** Turns one sparse source row into a canonical, encrypted-stage payload. */
final class RowTransformer
{
    public const ANSWER_FIELDS = [
        'demographics', 'demographics_other', 'age_range',
        'heard', 'heard_other', 'promote', 'relationship',
    ];

    public function __construct(private \DateTimeZone $timezone)
    {
    }

    /**
     * @param array<int,string> $cells
     * @param array<string,array{columns:list<int>,reducer:string}> $mapping
     * @return array{payload:array<string,mixed>,issues:list<string>}
     */
    public function transform(int $rowNo, array $cells, array $mapping): array
    {
        $issues = [];
        $value = function (string $field) use ($cells, $mapping, &$issues): mixed {
            return $this->mappedValue($field, $cells, (array) ($mapping[$field] ?? []), $issues);
        };

        $emailRaw = trim((string) $value('email'));
        $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? strtolower($emailRaw) : '';
        if ($emailRaw !== '' && $email === '') $issues[] = 'attendee_email_invalid';

        $orderDate = $this->date((string) $value('order_date'));
        $eventDate = $this->date((string) $value('event_date'));
        if ($orderDate === null) $issues[] = 'order_date_invalid';
        if ($eventDate === null) $issues[] = 'event_date_invalid';

        $demographics = (array) $value('demographics');
        $demographicsOther = trim((string) $value('demographics_other'));
        if ($demographicsOther !== '' && !in_array('Other', $demographics, true)) {
            $demographics[] = 'Other';
        }

        $heard = (array) $value('heard');
        $heardOther = trim((string) $value('heard_other'));
        if ($heardOther !== '' && !in_array('Other', $heard, true)) {
            $heard[] = 'Other';
        }

        $first = $this->plain((string) $value('first_name'), 100);
        $last = $this->plain((string) $value('last_name'), 100);
        $eventId = $this->identifier((string) $value('event_id'));
        $order = $this->identifier((string) $value('order_number'));
        $eventName = $this->plain((string) $value('event_name'), 240);

        if ($first === '' && $last === '') $issues[] = 'attendee_name_missing';
        if ($eventId === '') $issues[] = 'event_id_missing';
        if ($eventName === '') $issues[] = 'event_name_missing';
        if ($order === '') $issues[] = 'order_number_missing';

        $statusRaw = $this->plain((string) $value('attendee_status'), 64);
        $registrationStatus = $this->registrationStatus($statusRaw);
        if ($registrationStatus === 'excluded') $issues[] = 'source_registration_not_active';

        $sharing = $value('contact_sharing');
        $payload = [
            'source_row' => $rowNo,
            'source' => [
                'event_id' => $eventId,
                'order_number' => $order,
                'order_type' => $this->plain((string) $value('order_type'), 100),
                'ticket_type' => $this->plain((string) $value('ticket_type'), 240),
                'total_paid' => $this->moneyEvidence((string) $value('total_paid')),
                'attendee_status' => $statusRaw,
                // Buyer identity exists only in encrypted staging and is
                // intentionally never copied into the attendee profile.
                'buyer_first_name' => $this->plain((string) $value('buyer_first_name'), 100),
                'buyer_last_name' => $this->plain((string) $value('buyer_last_name'), 100),
                'buyer_email' => $this->validEmail((string) $value('buyer_email')),
            ],
            'event' => [
                'external_id' => $eventId,
                'title' => $eventName,
                'date' => $eventDate?->format('Y-m-d') ?? '',
                'time' => $eventDate?->format('g:i A') ?? '',
            ],
            'person' => [
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'phone' => $this->plain((string) $value('phone'), 80),
                'address1' => $this->plain((string) $value('address1'), 190),
                'address2' => $this->plain((string) $value('address2'), 190),
                'city' => $this->plain((string) $value('city'), 100),
                'state' => $this->plain((string) $value('state'), 100),
                'postal' => $this->plain((string) $value('postal'), 32),
                'notes' => '',
            ],
            'registration' => [
                'created_at' => $orderDate?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') ?? '',
                'status' => $registrationStatus,
                'tier' => 'historical',
                'amount_cents' => 0,
            ],
            'answers' => [
                'demographics' => array_values(array_unique($demographics)),
                'demographics_other' => $demographicsOther,
                'age_range' => (string) $value('age_range'),
                'relationship' => (array) $value('relationship'),
                'heard' => array_values(array_unique($heard)),
                'heard_other' => $heardOther,
                'promote' => (string) $value('promote'),
            ],
            'contact_sharing' => is_bool($sharing) ? $sharing : null,
        ];
        return ['payload' => $payload, 'issues' => array_values(array_unique($issues))];
    }

    /** @param list<string> $issues */
    private function mappedValue(string $field, array $cells, array $entry, array &$issues): mixed
    {
        $values = [];
        foreach ((array) ($entry['columns'] ?? []) as $column) {
            $raw = trim((string) ($cells[(int) $column] ?? ''));
            if ($raw !== '') $values[] = $raw;
        }
        if (!$values) return $this->emptyFor($field);
        $reducer = (string) ($entry['reducer'] ?? 'first');
        if (in_array($reducer, ['first', 'priority'], true)) return $values[0];

        $canonical = [];
        foreach ($values as $raw) {
            $converted = $this->canonical($field, $raw);
            if ($converted === '' || $converted === []) {
                $issues[] = 'unmapped_value_dropped:' . $field;
                continue;
            }
            $fingerprint = $this->fingerprint($converted);
            $canonical[$fingerprint] = $converted;
        }
        if (count($canonical) > 1) {
            $issues[] = 'conflict_dropped:' . $field;
            return $this->emptyFor($field);
        }
        return $canonical ? reset($canonical) : $this->emptyFor($field);
    }

    private function canonical(string $field, string $raw): mixed
    {
        return match ($field) {
            'demographics' => $this->demographics($raw),
            'age_range' => $this->age($raw),
            'heard' => $this->heard($raw),
            'promote' => $this->promote($raw),
            'relationship' => $this->relationship($raw),
            'contact_sharing' => $this->sharing($raw),
            default => trim($raw),
        };
    }

    private function emptyFor(string $field): mixed
    {
        if (in_array($field, ['demographics', 'heard', 'relationship'], true)) return [];
        if ($field === 'contact_sharing') return null;
        return '';
    }

    private function fingerprint(mixed $value): string
    {
        if (is_array($value)) {
            $value = array_values(array_unique(array_map('strval', $value)));
            sort($value, SORT_NATURAL | SORT_FLAG_CASE);
        }
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<string> */
    private function demographics(string $raw): array
    {
        if (trim($raw) === '') return [];
        if (stripos($raw, 'prefer not') !== false) return [DemographicFields::PNTS_KEY];
        return DemographicFields::sanitize(PrefillResolver::parseOptions($raw, DemographicFields::options()));
    }

    /**
     * An exact or contained option first; otherwise a plain number of years
     * is placed in the option whose "25-34", "65+", or "Under 18" range
     * contains it.
     */
    private function age(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        $options = SurveyFields::ageRanges();
        if (stripos($raw, 'prefer not') !== false && in_array(DemographicFields::PNTS_KEY, $options, true)) {
            return DemographicFields::PNTS_KEY;
        }
        $parsed = PrefillResolver::parseAge($raw);
        if ($parsed !== '') return $parsed;
        if (!preg_match('/^\d{1,3}$/', $raw)) return '';
        $years = (int) $raw;
        foreach ($options as $option) {
            if (preg_match('/(\d+)\s*(?:-|to)\s*(\d+)/', $option, $m) && $years >= (int) $m[1] && $years <= (int) $m[2]) return $option;
            if (preg_match('/(\d+)\s*\+|(\d+)\s*(?:and|or)\s*(?:over|older)/i', $option, $m) && $years >= (int) ($m[1] !== '' ? $m[1] : $m[2])) return $option;
            if (preg_match('/(?:under|below)\s*(\d+)/i', $option, $m) && $years < (int) $m[1]) return $option;
        }
        return '';
    }

    /** @return list<string> */
    private function heard(string $raw): array
    {
        return SurveyFields::parseHeard($raw);
    }

    private function promote(string $raw): string
    {
        $hit = PrefillResolver::parseOptions($raw, SurveyFields::promoteOptions());
        return count($hit) === 1 ? $hit[0] : '';
    }

    /** @return list<string> */
    private function relationship(string $raw): array
    {
        if (stripos($raw, 'prefer not') !== false) return [DemographicFields::PNTS_KEY];
        return SurveyFields::sanitizeRelationship(PrefillResolver::parseOptions($raw, SurveyFields::relationshipOptions()));
    }

    private function sharing(string $raw): ?bool
    {
        $n = FieldMatcher::normalize($raw);
        if ($n === '') return null;
        if (str_contains($n, 'do not share') || str_contains($n, 'not share') || $n === 'no') return false;
        if ($n === 'yes' || str_contains($n, 'may share') || str_contains($n, 'share my contact')) return true;
        return null;
    }

    private function registrationStatus(string $raw): string
    {
        $n = FieldMatcher::normalize($raw);
        if ($n === '' || in_array($n, ['attending', 'confirmed', 'complete', 'completed', 'registered'], true)) return 'confirmed';
        if (str_contains($n, 'cancel') || str_contains($n, 'refund') || str_contains($n, 'not attending')) return 'excluded';
        return 'confirmed';
    }

    private function date(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (is_numeric($raw)) {
            $serial = (float) $raw;
            if ($serial > 20000 && $serial < 100000) {
                $seconds = (int) round($serial * 86400);
                // Excel serials are timezone-free wall-clock values. Treat
                // them in the site's timezone, then convert registration
                // timestamps to UTC at the payload boundary.
                return (new \DateTimeImmutable('1899-12-30 00:00:00', $this->timezone))
                    ->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
            }
        }
        foreach ([
            'M j, Y \\a\\t g:i A', 'F j, Y \\a\\t g:i A',
            'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'm/d/Y H:i', 'm/d/Y',
        ] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $raw, $this->timezone);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) return $date;
        }
        try {
            return new \DateTimeImmutable($raw, $this->timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private function validEmail(string $raw): string
    {
        $raw = strtolower(trim($raw));
        return filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : '';
    }

    private function moneyEvidence(string $raw): ?float
    {
        $raw = preg_replace('/[^0-9.\-]/', '', trim($raw)) ?: '';
        return $raw !== '' && is_numeric($raw) ? round((float) $raw, 2) : null;
    }

    private function identifier(string $raw): string
    {
        return bg_substr(trim(strip_tags($raw)), 0, 190);
    }

    private function plain(string $raw, int $length): string
    {
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = trim((string) preg_replace('/\s+/u', ' ', strip_tags($raw)));
        return bg_substr($raw, 0, $length);
    }
}
