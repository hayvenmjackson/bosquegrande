<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Lgl;

use BosqueGrande\Core\Logger;
use BosqueGrande\Core\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Typed adapter for the Little Green Light REST API v1
 * (https://api.littlegreenlight.com/api/v1). Nothing fetched here is
 * cached at rest.
 *
 * Auth: Bearer API key from the encrypted settings.
 *
 * Rate limiting: every call routes through request() with exponential
 * backoff on 429/5xx; broad pulls are also split into scheduled chunks.
 */
final class LglClient
{
    private const CATMAP_TRANSIENT = 'bg_lgl_catmap';

    private const ROOT = 'https://api.littlegreenlight.com/api/v1';
    private const MAX_RETRIES = 3;
    private bool $lastRequestSucceeded = true;

    public function __construct(
        private Settings $settings,
        private Logger $logger,
    ) {
    }

    public function hasKey(): bool
    {
        return $this->apiKey() !== '';
    }

    public function lastRequestSucceeded(): bool
    {
        return $this->lastRequestSucceeded;
    }

    /* ── Constituents ─────────────────────────────────────────────── */

    /**
     * Find an active constituent by an exact email-address match.
     *
     * LGL's constituent search grammar uses q[]=eaddr={address}; the
     * previous eq:email_address,{address} expression is not a supported
     * search term and therefore returned no constituent. Because eaddr
     * is a contains search, inspect the expanded addresses and accept
     * only an exact, current match before issuing an identity token.
     */
    public function findConstituentByEmail(string $email): ?array
    {
        $matches = $this->findConstituentsByEmail($email);
        return $matches[0] ?? null;
    }

    /**
     * Return every active constituent with the exact current address.
     * A household inbox can legitimately belong to several people, so
     * callers must inspect the complete result set instead of silently
     * accepting whichever record LGL returns first.
     *
     * @return list<array<string, mixed>>
     */
    public function findConstituentsByEmail(string $email): array
    {
        $needle = strtolower(trim($email));
        if ($needle === '') return [];

        $res = $this->request('GET', '/constituents/search', [
            'q[]' => 'eaddr=' . $needle,
            'expand' => 'email_addresses',
            'limit' => 25,
        ]);

        $matches = [];
        foreach (($res['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            foreach (($item['email_addresses'] ?? []) as $address) {
                if (!is_array($address) || !empty($address['not_current'])) continue;
                $candidate = strtolower(trim((string) ($address['address'] ?? '')));
                if ($candidate !== '' && hash_equals($needle, $candidate)) {
                    $id = (int) ($item['id'] ?? 0);
                    if ($id > 0) $matches[$id] = $item;
                    break;
                }
            }
        }
        return array_values($matches);
    }

    /** Full constituent detail with related objects for prefill. */
    public function getConstituent(int $id): ?array
    {
        $res = $this->request('GET', "/constituents/{$id}", [
            'expand' => 'class_affiliations,relationships,street_addresses,phone_numbers,email_addresses,web_addresses,categories,groups,memberships,custom_attrs',
        ]);
        return $res ?: null;
    }

    /** @param array<string, mixed> $payload LGL constituent shape. */
    public function createConstituent(array $payload): ?array
    {
        return $this->request('POST', '/constituents', [], $payload) ?: null;
    }

    /** @param array<string, mixed> $payload Partial update, related objects allowed. */
    public function updateConstituent(int $id, array $payload): ?array
    {
        return $this->request('PATCH', "/constituents/{$id}", [], $payload) ?: null;
    }

    /* ── Category writes ─────────────────────────────────── */

    /**
     * Route outbound person-level answers to whichever structure the
     * account actually uses. Some LGL accounts keep these
     * answers in CUSTOM ATTRIBUTES (one free-text value under a name
     * the account chose) rather than categories; writing categories at
     * such an account would split the data into a parallel structure.
     * When the constituent record on hand shows a matching custom
     * attribute and the account has no matching category, the answer
     * is written to that attribute (values joined '; ', the same
     * round-trippable format the local store uses); everything else
     * takes the category path below. Returns a payload fragment with
     * 'categories' and/or 'custom_attrs' keys to merge into the PATCH.
     *
     * @param array<int, array{name: string, keywords: array<int, array{name: string}>}> $categories
     * @param array<string, mixed>|null $constituentRecord A fetched LGL
     *        record when one is at hand (the prefill cache usually has
     *        it); null skips attribute routing rather than costing an
     *        extra request.
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function answerPayload(array $categories, ?array $constituentRecord = null): array
    {
        if (!$categories) return [];
        $attrs = [];
        if (is_array($constituentRecord)) {
            $attrs = array_values(array_filter(
                (array) ($constituentRecord['custom_attrs'] ?? []), 'is_array'
            ));
        }
        $toCategories = [];
        $attrWrites = [];
        $account = null;
        foreach ($categories as $wanted) {
            $name = (string) ($wanted['name'] ?? '');
            if ($name === '') continue;
            $attr = $attrs ? CategoryMatcher::matchAttr($attrs, $name) : null;
            if ($attr) {
                if ($account === null) $account = $this->accountCategories() ?? [];
                if (!CategoryMatcher::match($account, $name)) {
                    $values = [];
                    foreach ((array) ($wanted['keywords'] ?? []) as $kw) {
                        $kwName = (string) (is_array($kw) ? ($kw['name'] ?? '') : $kw);
                        if ($kwName !== '') $values[] = $kwName;
                    }
                    $entry = ['value' => implode('; ', $values)];
                    if (!empty($attr['key'])) {
                        $entry['key'] = (string) $attr['key'];
                    } elseif (!empty($attr['id'])) {
                        $entry['id'] = (int) $attr['id'];
                    }
                    $attrWrites[] = $entry;
                    continue;
                }
            }
            $toCategories[] = $wanted;
        }
        $out = [];
        if ($toCategories) $out['categories'] = $this->categoriesWithIds($toCategories);
        if ($attrWrites) $out['custom_attrs'] = $attrWrites;
        return $out;
    }

    /**
     * Enrich outbound category structures with real LGL ids. The LGL
     * API requires keyword ids on category updates; the old name-only
     * payload silently failed to land on accounts where the keyword
     * values did not already exist, so the answers never reached LGL
     * and never came back at prefill time. This resolver:
     *
     *   1. fetches the account's constituent categories once per
     *      twelve hours (names and keyword names are schema, cached in
     *      a transient; keyword VALUES never appear in any log),
     *   2. matches each outbound category to an account category via
     *      CategoryMatcher (same tolerant tiers the read side uses, so
     *      a fuzzy read can never coexist with an exact write creating
     *      a duplicate category), creating the category when genuinely
     *      absent,
     *   3. creates any missing keywords under the matched category and
     *      attaches ids throughout.
     *
     * Any transport failure returns the input unchanged: enrichment
     * can only improve a write, never block one.
     *
     * @param array<int, array{name: string, keywords: array<int, array{name: string}>}> $categories
     * @return array<int, array<string, mixed>>
     */
    public function categoriesWithIds(array $categories): array
    {
        if (!$categories || !$this->hasKey()) return $categories;
        $account = $this->accountCategories();
        if ($account === null) return $categories;

        $out = [];
        $mapChanged = false;
        foreach ($categories as $wanted) {
            $name = (string) ($wanted['name'] ?? '');
            $keywords = (array) ($wanted['keywords'] ?? []);
            if ($name === '') continue;

            $matched = CategoryMatcher::match($account, $name);
            if (!$matched) {
                $created = $this->request('POST', '/categories', [], [
                    'item_type' => 'Constituent',
                    'name' => $name,
                ]);
                if (is_array($created) && !empty($created['id'])) {
                    $matched = $created;
                    $account[] = $created;
                    $mapChanged = true;
                    $this->logger->info('lgl', 'Constituent category created in LGL.', ['name' => $name]);
                }
            }
            if (!$matched) {
                $out[] = $wanted; // Name-only fallback: previous behavior.
                continue;
            }

            $catId = (int) ($matched['id'] ?? 0);
            $existing = [];
            foreach ((array) ($matched['keywords'] ?? []) as $kw) {
                if (is_array($kw) && isset($kw['name'])) {
                    $existing[strtolower((string) $kw['name'])] = $kw;
                }
            }
            $enriched = [];
            foreach ($keywords as $kw) {
                $kwName = (string) (is_array($kw) ? ($kw['name'] ?? '') : $kw);
                if ($kwName === '') continue;
                $have = $existing[strtolower($kwName)] ?? null;
                if (!$have && $catId) {
                    $made = $this->request('POST', "/categories/{$catId}/keywords", [], ['name' => $kwName]);
                    if (is_array($made) && !empty($made['id'])) {
                        $have = $made;
                        $matched['keywords'][] = $made;
                        $existing[strtolower($kwName)] = $made;
                        $mapChanged = true;
                    }
                }
                $enriched[] = $have
                    ? ['id' => (int) $have['id'], 'name' => (string) ($have['name'] ?? $kwName)]
                    : ['name' => $kwName];
            }
            $entry = [
                'name' => (string) ($matched['name'] ?? $name),
                'keywords' => $enriched,
            ];
            if ($catId) $entry['id'] = $catId;
            $out[] = $entry;

            // Keep the cached map current with the created keywords.
            foreach ($account as $i => $cat) {
                if ((int) ($cat['id'] ?? 0) === $catId) {
                    $account[$i] = $matched;
                    break;
                }
            }
        }
        if ($mapChanged) {
            set_transient(self::CATMAP_TRANSIENT, $account, 12 * HOUR_IN_SECONDS);
        }
        return $out;
    }

    /**
     * The account's constituent categories, cached twelve hours.
     * @return array<int, array<string, mixed>>|null Null on transport failure.
     */
    private function accountCategories(): ?array
    {
        $cached = get_transient(self::CATMAP_TRANSIENT);
        if (is_array($cached)) return $cached;
        $all = [];
        $offset = 0;
        do {
            $res = $this->request('GET', '/categories', [
                'item_type' => 'Constituent',
                'limit' => 100,
                'offset' => $offset,
            ]);
            if (!is_array($res)) return null;
            $items = array_values(array_filter((array) ($res['items'] ?? []), 'is_array'));
            $all = array_merge($all, $items);
            $offset += count($items);
            $more = count($items) === 100 && $offset < (int) ($res['total_items'] ?? 0);
        } while ($more);
        set_transient(self::CATMAP_TRANSIENT, $all, 12 * HOUR_IN_SECONDS);
        return $all;
    }

    /* ── Events and invitations ───────────────────────────────────── */

    /** @return array<int, array<string, mixed>> */
    public function listEvents(int $limit = 100, int $offset = 0): array
    {
        $res = $this->request('GET', '/events', ['limit' => $limit, 'offset' => $offset]);
        return $res['items'] ?? [];
    }

    /**
     * Create an LGL event (used by the Eventbrite migrator). The code
     * field is the selector the calendar sync matches on.
     *
     * @param array<string, mixed> $payload
     */
    public function createEvent(array $payload): ?array
    {
        return $this->request('POST', '/events', [], array_filter($payload)) ?: null;
    }

    /**
     * Register attendance: add an invitation for a constituent on an
     * event. rsvp/attended semantics follow LGL invitation fields.
     *
     * @param array<string, mixed> $extra
     */
    public function addInvitation(int $eventId, int $constituentId, array $extra = []): ?array
    {
        $payload = array_merge([
            'constituent_id' => $constituentId,
            'rsvp' => 'Yes',
        ], $extra);
        return $this->request('POST', "/events/{$eventId}/invitations", [], $payload) ?: null;
    }

    public function deleteInvitation(int $invitationId): bool
    {
        return $this->request('DELETE', "/invitations/{$invitationId}") !== null;
    }

    /* ── Gifts (payment write-back) ───────────────────────────────── */

    /** @param array<string, mixed> $payload LGL gift shape (amount, date, note, etc). */
    public function addGift(int $constituentId, array $payload): ?array
    {
        return $this->request('POST', "/constituents/{$constituentId}/gifts", [], $payload) ?: null;
    }

    public function deleteGift(int $giftId): bool
    {
        return $this->request('DELETE', "/gifts/{$giftId}") !== null;
    }

    /* ── Membership / monthly-donor gating ────────────────────────── */

    /** @return array<int, array<string, mixed>> Most recent gifts first. */
    public function listGifts(int $constituentId, int $limit = 5): array
    {
        $res = $this->request('GET', "/constituents/{$constituentId}/gifts", [
            'limit' => $limit,
            'sort' => 'date!',
        ]);
        return $res['items'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function listConstituents(int $limit = 100, int $offset = 0): array
    {
        $res = $this->request('GET', '/constituents', [
            'limit' => $limit,
            'offset' => $offset,
            // Expanded so pull sync sees the same shape as the
            // single-record fetch, including every related constituent
            // object used by the field mapper.
            'expand' => 'class_affiliations,relationships,street_addresses,phone_numbers,email_addresses,web_addresses,categories,groups,memberships,custom_attrs',
        ]);
        return $res['items'] ?? [];
    }

    /**
     * True when the constituent carries an explicit Monthly Donor = Yes
     * custom attribute/category. Legacy group and membership modes remain
     * readable for older integrations. Gift recency is deliberately not a
     * fallback: a recent one-time gift is not proof of an active schedule.
     */
    public function isMonthlyDonor(int $constituentId): bool
    {
        $mode = (string) $this->settings->get('member_check_mode', 'giving_status');
        $value = (string) $this->settings->get('member_check_value', 'Active Donor');
        $c = $this->getConstituent($constituentId);
        if (!$c) return false;

        if ($mode === 'giving_status') {
            $fieldName = (string) apply_filters('bg_lgl_monthly_donor_field', 'Monthly Donor');
            $attr = CategoryMatcher::matchAttr(
                array_values(array_filter((array) ($c['custom_attrs'] ?? []), 'is_array')),
                $fieldName
            );
            $raw = $attr ? trim((string) ($attr['value'] ?? $attr['description'] ?? '')) : '';
            if ($raw === '') {
                $cat = CategoryMatcher::match(
                    array_values(array_filter((array) ($c['categories'] ?? []), 'is_array')),
                    $fieldName
                );
                if ($cat) {
                    $parts = [];
                    foreach ((array) ($cat['keywords'] ?? []) as $keyword) {
                        $part = trim((string) (is_array($keyword) ? ($keyword['name'] ?? '') : $keyword));
                        if ($part !== '') $parts[] = $part;
                    }
                    $raw = implode('; ', $parts);
                }
            }
            return \BosqueGrande\Modules\Constituents\CoreFields::normalizeMonthlyDonor($raw) === 'Yes';
        }

        if ($mode === 'membership') {
            foreach (($c['memberships'] ?? []) as $m) {
                $active = empty($m['finish_date']) || strtotime((string) $m['finish_date']) >= time();
                $levelOk = $value === '' || stripos((string) ($m['level_name'] ?? ''), $value) !== false;
                if ($active && $levelOk) return true;
            }
            return false;
        }

        foreach (($c['groups'] ?? []) as $g) {
            $name = is_array($g)
                ? (string) ($g['group_name'] ?? $g['name'] ?? '')
                : (string) $g;
            if (strcasecmp($name, $value) === 0) return true;
        }
        return false;
    }

    /**
     * Mirror BosqueGrande's resolved monthly-donor status into LGL.
     *
     * Accounts represent house fields in either custom attributes or
     * constituent categories. answerPayload() already detects that live
     * shape and updates the existing location; when neither exists it
     * creates the Monthly Donor category and its Yes/No keyword through
     * the same documented API used by the connector.
     */
    public function setMonthlyDonorStatus(int $constituentId, bool $active): bool
    {
        if ($constituentId < 1 || !$this->hasKey()) return false;
        $constituent = $this->getConstituent($constituentId);
        if (!is_array($constituent)) return false;

        $name = (string) apply_filters('bg_lgl_monthly_donor_field', 'Monthly Donor');
        $payload = $this->answerPayload([[
            'name' => $name,
            'keywords' => [['name' => $active ? 'Yes' : 'No']],
        ]], $constituent);
        if ($payload === []) return false;
        return $this->updateConstituent($constituentId, $payload) !== null;
    }

    /* ── Custom attributes (Tier 1 token, demographic mapping) ────── */

    /** @return array<int, array<string, mixed>> Account-level custom attribute definitions. */
    public function listCustomAttributes(): array
    {
        $all = [];
        $offset = 0;
        do {
            $res = $this->request('GET', '/attributes', [
                'limit' => 100,
                'offset' => $offset,
            ]);
            if (!is_array($res)) return $all;
            $items = array_values(array_filter((array) ($res['items'] ?? []), 'is_array'));
            $all = array_merge($all, $items);
            $offset += count($items);
            $more = count($items) === 100
                && (!array_key_exists('total_items', $res)
                    || $offset < (int) $res['total_items']);
        } while ($more && $offset < 10000);
        return $all;
    }

    /**
     * Write one custom attribute value on a constituent. Uses the
     * constituent update endpoint with the custom_attrs shape from the
     * LGL docs. NOTE: verify the exact accepted shape against the live
     * account during integration testing; LGL's static docs show
     * writes via update with related objects.
     */
    public function setCustomAttribute(int $constituentId, int $attributeId, string $value): bool
    {
        $res = $this->updateConstituent($constituentId, [
            'custom_attrs' => [
                ['id' => $attributeId, 'value' => $value],
            ],
        ]);
        return $res !== null;
    }

    /* ── Transport ────────────────────────────────────────────────── */

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null Decoded JSON, [] for empty 2xx, null on failure.
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): ?array
    {
        $this->lastRequestSucceeded = false;
        $key = $this->apiKey();
        if ($key === '') {
            $this->logger->warn('lgl', 'LGL call attempted without an API key.', ['path' => $path]);
            return null;
        }

        $url = self::ROOT . $path;
        if ($query) $url = add_query_arg(array_map('strval', $query), $url);

        $args = [
            'method' => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body);

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                usleep((int) (250000 * (2 ** ($attempt - 1)))); // 250ms, 500ms, 1s
            }
            $response = wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                $this->logger->warn('lgl', 'LGL transport error.', ['path' => $path, 'error' => $response->get_error_message()]);
                continue;
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 429 || $code >= 500) {
                $this->logger->warn('lgl', "LGL returned {$code}, retrying.", ['path' => $path, 'attempt' => $attempt]);
                continue;
            }
            if ($code >= 400) {
                $this->logger->error('lgl', "LGL request failed ({$code}).", ['path' => $path, 'method' => $method]);
                return null;
            }
            $raw = wp_remote_retrieve_body($response);
            if ($raw === '') {
                $this->lastRequestSucceeded = true;
                return [];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $this->logger->error('lgl', 'LGL returned a response that was not valid JSON.', ['path' => $path]);
                return null;
            }
            $this->lastRequestSucceeded = true;
            return $decoded;
        }

        $this->logger->error('lgl', 'LGL request exhausted retries.', ['path' => $path]);
        return null;
    }

    private function apiKey(): string
    {
        return (string) $this->settings->get('lgl_api_key', '');
    }
}
