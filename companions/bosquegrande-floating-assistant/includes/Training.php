<?php

declare(strict_types=1);

namespace BGCompanion\Assistant;

if (!defined('ABSPATH')) exit;

/**
 * Training info: the organization's own reference material for the
 * assistant.
 *
 * Each entry is marked for "visitor" (visitors and staff) or "staff" (staff
 * only). Entries are split into passages under their headings. For each
 * question, only the passages that match it are sent to the service
 * provider, within a fixed character budget, so a large library does not
 * make every question expensive. Entries marked "always include" contribute
 * their opening text to every answer for their audience (for short key
 * facts such as hours and contact details).
 */
final class Training
{
    public const DB_VERSION = 1;
    public const AUDIENCES = ['visitor', 'staff'];
    public const MAX_DOC_CHARS = 300000;
    public const MAX_TOTAL_CHARS = 3000000;
    public const PINNED_BUDGET = 2500;
    private const CHUNK_TARGET = 900;
    private const MAX_PASSAGES = 8;
    private const BUDGET = ['visitor' => 6000, 'staff' => 9000];
    private const CANDIDATES = 400;

    private const STOPWORDS = [
        'a', 'about', 'after', 'again', 'all', 'also', 'am', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'been',
        'before', 'being', 'but', 'by', 'can', 'could', 'did', 'do', 'does', 'doing', 'for', 'from', 'get', 'got',
        'had', 'has', 'have', 'having', 'he', 'her', 'here', 'hers', 'him', 'his', 'how', 'i', 'if', 'in', 'into',
        'is', 'it', 'its', 'just', 'know', 'let', 'like', 'me', 'more', 'most', 'my', 'need', 'no', 'not', 'now',
        'of', 'on', 'one', 'or', 'our', 'ours', 'out', 'please', 'should', 'so', 'some', 'such', 'tell', 'than',
        'thank', 'thanks', 'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'those', 'to',
        'too', 'us', 'use', 'very', 'want', 'was', 'way', 'we', 'were', 'what', 'when', 'where', 'which', 'while',
        'who', 'whom', 'why', 'will', 'with', 'would', 'you', 'your', 'yours', 'hi', 'hello', 'hey',
    ];

    public static function docsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bgca_training_docs';
    }

    public static function chunksTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bgca_training_chunks';
    }

    /* ── Storage ──────────────────────────────────────────────── */

    /** Create or upgrade this site's tables when they are missing or older. */
    public static function maybeInstall(): void
    {
        if ((int) get_option('bgca_db_version', 0) >= self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::docsTable() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(191) NOT NULL DEFAULT '',
  audience varchar(16) NOT NULL DEFAULT 'visitor',
  pinned tinyint(1) NOT NULL DEFAULT 0,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  source_name varchar(191) NOT NULL DEFAULT '',
  source_type varchar(16) NOT NULL DEFAULT '',
  chars int(10) unsigned NOT NULL DEFAULT 0,
  body longtext NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY audience_enabled (audience,enabled)
) $charset;");
        dbDelta("CREATE TABLE " . self::chunksTable() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  doc_id bigint(20) unsigned NOT NULL,
  position int(10) unsigned NOT NULL DEFAULT 0,
  heading varchar(191) NOT NULL DEFAULT '',
  body text NOT NULL,
  PRIMARY KEY  (id),
  KEY doc_position (doc_id,position)
) $charset;");
        update_option('bgca_db_version', self::DB_VERSION, false);
    }

    /** @return list<object> Entries without their text, newest first. */
    public static function all(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT id, title, audience, pinned, enabled, source_name, source_type, chars, created_at, updated_at FROM '
            . self::docsTable() . ' ORDER BY updated_at DESC, id DESC'
        ) ?: [];
    }

    public static function get(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::docsTable() . ' WHERE id = %d', $id));
        return $row ?: null;
    }

    public static function totalChars(int $exceptId = 0): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(chars), 0) FROM ' . self::docsTable() . ' WHERE id <> %d', $exceptId));
    }

    /**
     * Insert (id 0) or update an entry and rebuild its passages.
     *
     * @param array{title: string, audience: string, pinned: bool, enabled: bool, source_name?: string, source_type?: string} $meta
     * @return int|\WP_Error The entry id.
     */
    public static function save(int $id, array $meta, string $text)
    {
        global $wpdb;
        $text = Extractor::clean($text);
        $title = trim(sanitize_text_field($meta['title']));
        if ($title === '') return new \WP_Error('title', 'Give the training info a title.');
        if ($text === '') return new \WP_Error('empty', 'There is no text to save.');
        $length = self::length($text);
        if ($length > self::MAX_DOC_CHARS) {
            return new \WP_Error('long', sprintf('That is %s characters; one entry can hold up to %s. Split it into smaller entries.', number_format_i18n($length), number_format_i18n(self::MAX_DOC_CHARS)));
        }
        if (self::totalChars($id) + $length > self::MAX_TOTAL_CHARS) {
            return new \WP_Error('full', sprintf('Training info is limited to %s characters in total. Remove or shorten older entries first.', number_format_i18n(self::MAX_TOTAL_CHARS)));
        }
        $audience = in_array($meta['audience'], self::AUDIENCES, true) ? $meta['audience'] : 'visitor';
        $now = current_time('mysql', true);
        $row = [
            'title' => mb_substr($title, 0, 180),
            'audience' => $audience,
            'pinned' => $meta['pinned'] ? 1 : 0,
            'enabled' => $meta['enabled'] ? 1 : 0,
            'chars' => $length,
            'body' => $text,
            'updated_at' => $now,
        ];
        if (isset($meta['source_name'])) $row['source_name'] = mb_substr(sanitize_file_name($meta['source_name']), 0, 180);
        if (isset($meta['source_type'])) $row['source_type'] = sanitize_key($meta['source_type']);

        $wpdb->query('START TRANSACTION');
        if ($id > 0) {
            $ok = $wpdb->update(self::docsTable(), $row, ['id' => $id]) !== false;
        } else {
            $row['created_at'] = $now;
            $row['created_by'] = get_current_user_id();
            $ok = (bool) $wpdb->insert(self::docsTable(), $row);
            $id = (int) $wpdb->insert_id;
        }
        if ($ok) $ok = $wpdb->delete(self::chunksTable(), ['doc_id' => $id]) !== false;
        if ($ok) {
            foreach (self::chunk($text) as $position => $chunk) {
                $ok = (bool) $wpdb->insert(self::chunksTable(), [
                    'doc_id' => $id,
                    'position' => $position,
                    'heading' => mb_substr($chunk['heading'], 0, 180),
                    'body' => $chunk['body'],
                ]);
                if (!$ok) break;
            }
        }
        if (!$ok) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('db', 'The training info could not be saved. Try again, or check the site\'s database.');
        }
        $wpdb->query('COMMIT');
        return $id;
    }

    public static function setEnabled(int $id, bool $enabled): bool
    {
        global $wpdb;
        return $wpdb->update(self::docsTable(), ['enabled' => $enabled ? 1 : 0, 'updated_at' => current_time('mysql', true)], ['id' => $id]) !== false;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        $wpdb->delete(self::chunksTable(), ['doc_id' => $id]);
        return (bool) $wpdb->delete(self::docsTable(), ['id' => $id]);
    }

    public static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /* ── Passages ─────────────────────────────────────────────── */

    /**
     * Split text into passages of roughly CHUNK_TARGET characters that never
     * cross a heading, and remember the heading each one sits under.
     *
     * @return list<array{heading: string, body: string}>
     */
    public static function chunk(string $text): array
    {
        $paragraphs = [];
        $heading = '';
        $current = '';
        $previous = null;
        $flushParagraph = function () use (&$paragraphs, &$current, &$heading): void {
            if (trim($current) !== '') $paragraphs[] = ['heading' => $heading, 'text' => trim($current)];
            $current = '';
        };
        foreach (explode("\n", $text) as $raw) {
            $line = trim($raw);
            if ($line === '') {
                $flushParagraph();
                $previous = '';
                continue;
            }
            if (self::isHeading($line, $previous)) {
                $flushParagraph();
                $heading = trim(ltrim($line, '#'));
                $previous = null;
                continue;
            }
            $current .= ($current === '' ? '' : "\n") . $line;
            $previous = $line;
        }
        $flushParagraph();

        $chunks = [];
        $buffer = '';
        $bufferHeading = '';
        $flush = function () use (&$chunks, &$buffer, &$bufferHeading): void {
            if (trim($buffer) !== '') $chunks[] = ['heading' => $bufferHeading, 'body' => trim($buffer)];
            $buffer = '';
        };
        foreach ($paragraphs as $p) {
            if ($p['heading'] !== $bufferHeading) {
                $flush();
                $bufferHeading = $p['heading'];
            }
            foreach (self::pieces($p['text']) as $piece) {
                if ($buffer !== '' && self::length($buffer) + self::length($piece) + 2 > self::CHUNK_TARGET) $flush();
                $buffer .= ($buffer === '' ? '' : "\n\n") . $piece;
            }
        }
        $flush();
        return $chunks;
    }

    /** A line that names a section: Markdown heading, or a short capitalized line after a sentence end. */
    private static function isHeading(string $line, ?string $previous): bool
    {
        if (preg_match('/^#{1,6}\s+\S/', $line)) return true;
        if (self::length($line) > 70 || str_word_count($line) > 10) return false;
        if (preg_match('/^[-*\x{2022}|>]/u', $line)) return false;
        if (preg_match('/[.!?,;:)\]"\x{201D}\x{2019}]$/u', $line)) return false;
        if (!preg_match('/^[\p{Lu}\d]/u', $line)) return false;
        return $previous === null || $previous === '' || (bool) preg_match('/[.!?:]["\x{201D}\x{2019})]?$/u', $previous);
    }

    /** @return list<string> A paragraph, split at sentence ends when it is too long for one passage. */
    private static function pieces(string $paragraph): array
    {
        if (self::length($paragraph) <= self::CHUNK_TARGET) return [$paragraph];
        $sentences = preg_split('/(?<=[.!?])\s+(?=[\p{Lu}\d"\x{201C}(])/u', $paragraph) ?: [$paragraph];
        $out = [];
        $buffer = '';
        foreach ($sentences as $sentence) {
            while (self::length($sentence) > self::CHUNK_TARGET) {
                $cut = mb_substr($sentence, 0, self::CHUNK_TARGET);
                $space = mb_strrpos($cut, ' ');
                $cut = $space !== false && $space > self::CHUNK_TARGET / 2 ? mb_substr($cut, 0, $space) : $cut;
                if ($buffer !== '') {
                    $out[] = $buffer;
                    $buffer = '';
                }
                $out[] = $cut;
                $sentence = ltrim(mb_substr($sentence, mb_strlen($cut)));
            }
            if ($buffer !== '' && self::length($buffer) + self::length($sentence) + 1 > self::CHUNK_TARGET) {
                $out[] = $buffer;
                $buffer = '';
            }
            $buffer .= ($buffer === '' ? '' : ' ') . $sentence;
        }
        if (trim($buffer) !== '') $out[] = $buffer;
        return $out;
    }

    /* ── Retrieval ────────────────────────────────────────────── */

    /** Reference text for one question, for the assistant's prompt. */
    public static function reference(string $audience, string $question): string
    {
        $blocks = [];
        foreach (self::passages($audience, $question) as $p) {
            $label = $p['title'] . ($p['heading'] !== '' && $p['heading'] !== $p['title'] ? ' > ' . $p['heading'] : '');
            $blocks[] = '[' . $label . "]\n" . $p['body'];
        }
        return implode("\n\n", $blocks);
    }

    /**
     * The passages a question would send: "always include" openings first,
     * then the best matches, in reading order.
     *
     * @return list<array{title: string, heading: string, body: string, score: float, pinned: bool}>
     */
    public static function passages(string $audience, string $question): array
    {
        global $wpdb;
        if ((int) get_option('bgca_db_version', 0) < 1) return [];
        $audiences = $audience === 'staff' ? ['visitor', 'staff'] : ['visitor'];
        $in = implode(',', array_fill(0, count($audiences), '%s'));
        $budget = self::BUDGET[$audience === 'staff' ? 'staff' : 'visitor'];
        $used = [];
        $out = [];
        $spent = 0;

        // Always-included entries: their first passages, up to the pinned budget.
        $pinned = $wpdb->get_results($wpdb->prepare(
            'SELECT c.id, c.doc_id, c.position, c.heading, c.body, d.title FROM ' . self::chunksTable() . ' c
             JOIN ' . self::docsTable() . " d ON d.id = c.doc_id
             WHERE d.enabled = 1 AND d.pinned = 1 AND d.audience IN ($in)
             ORDER BY d.id ASC, c.position ASC LIMIT 60",
            ...$audiences
        )) ?: [];
        $pinnedSpent = 0;
        foreach ($pinned as $row) {
            $len = self::length((string) $row->body);
            if ($pinnedSpent + $len > self::PINNED_BUDGET) {
                if ($pinnedSpent === 0) {
                    $row->body = mb_substr((string) $row->body, 0, self::PINNED_BUDGET);
                    $len = self::PINNED_BUDGET;
                } else {
                    continue;
                }
            }
            $pinnedSpent += $len;
            $used[(int) $row->id] = true;
            $out[] = ['title' => (string) $row->title, 'heading' => (string) $row->heading, 'body' => (string) $row->body, 'score' => 0.0, 'pinned' => true];
        }
        $spent += $pinnedSpent;

        $keywords = self::keywords($question);
        if (!$keywords) return $out;

        // Prefilter in the database; when many passages match, keep the ones
        // matching the most distinct keywords, so a common word cannot crowd
        // out the passage that matches the specific ones.
        $likes = [];
        $likeArgs = [];
        foreach ($keywords as $k) {
            $likes[] = '(c.body LIKE %s OR c.heading LIKE %s OR d.title LIKE %s)';
            $like = '%' . $wpdb->esc_like(self::likeKey($k)) . '%';
            array_push($likeArgs, $like, $like, $like);
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT c.id, c.doc_id, c.position, c.heading, c.body, d.title FROM ' . self::chunksTable() . ' c
             JOIN ' . self::docsTable() . " d ON d.id = c.doc_id
             WHERE d.enabled = 1 AND d.audience IN ($in) AND (" . implode(' OR ', $likes) . ')
             ORDER BY (' . implode(' + ', $likes) . ') DESC, c.id ASC
             LIMIT ' . self::CANDIDATES,
            ...array_merge($audiences, $likeArgs, $likeArgs)
        )) ?: [];
        $total = max(count($rows), (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::chunksTable() . ' c JOIN ' . self::docsTable() . " d ON d.id = c.doc_id
             WHERE d.enabled = 1 AND d.audience IN ($in)",
            ...$audiences
        )));

        // BM25 over the candidates; heading and title words count double.
        $docs = [];
        $df = array_fill_keys($keywords, 0);
        $lengthSum = 0;
        foreach ($rows as $row) {
            $terms = array_count_values(array_merge(
                self::stems((string) $row->body),
                self::stems((string) $row->heading . ' ' . (string) $row->heading . ' ' . (string) $row->title . ' ' . (string) $row->title)
            ));
            $len = array_sum($terms);
            $lengthSum += $len;
            foreach ($keywords as $k) {
                if (isset($terms[$k])) $df[$k]++;
            }
            $docs[] = [$row, $terms, $len];
        }
        if (!$docs) return $out;
        $avg = $lengthSum / count($docs) ?: 1;
        $scored = [];
        foreach ($docs as [$row, $terms, $len]) {
            $score = 0.0;
            foreach ($keywords as $k) {
                $tf = $terms[$k] ?? 0;
                if ($tf === 0) continue;
                $idf = log(1 + ($total - $df[$k] + 0.5) / ($df[$k] + 0.5));
                $score += $idf * ($tf * 2.2) / ($tf + 1.2 * (0.25 + 0.75 * $len / $avg));
            }
            if ($score > 0 && !isset($used[(int) $row->id])) $scored[] = [$score, $row];
        }
        usort($scored, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        $picked = [];
        foreach ($scored as [$score, $row]) {
            if (count($picked) >= self::MAX_PASSAGES) break;
            $len = self::length((string) $row->body);
            if ($spent + $len > $budget) continue;
            $spent += $len;
            $picked[] = [$score, $row];
        }
        usort($picked, static fn(array $a, array $b): int
            => [(int) $a[1]->doc_id, (int) $a[1]->position] <=> [(int) $b[1]->doc_id, (int) $b[1]->position]);
        foreach ($picked as [$score, $row]) {
            $out[] = ['title' => (string) $row->title, 'heading' => (string) $row->heading, 'body' => (string) $row->body, 'score' => round((float) $score, 2), 'pinned' => false];
        }
        return $out;
    }

    /** @return list<string> Distinct stems of the meaningful words in a question. */
    public static function keywords(string $question): array
    {
        $words = [];
        foreach (self::words($question) as $word) {
            if (in_array($word, self::STOPWORDS, true)) continue;
            if (self::length($word) < 3 && !ctype_digit($word)) continue;
            $words[self::stem($word)] = true;
        }
        return array_slice(array_keys($words), 0, 12);
    }

    /** The part of a stem every spelling shares, for the database prefilter (policy matches policies). */
    private static function likeKey(string $stem): string
    {
        $len = self::length($stem);
        if ($len <= 3) return $stem;
        return mb_substr($stem, 0, str_ends_with($stem, 'y') ? $len - 1 : $len);
    }

    /** @return list<string> */
    private static function stems(string $text): array
    {
        return array_map([self::class, 'stem'], self::words($text));
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        if (function_exists('remove_accents')) $text = remove_accents($text);
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $m);
        return $m[0];
    }

    /**
     * Crude, language-light stemming: singular form, then the first six
     * letters, so register, registering, and registration meet.
     */
    public static function stem(string $word): string
    {
        $len = self::length($word);
        if ($len > 4 && str_ends_with($word, 'ies')) $word = mb_substr($word, 0, -3) . 'y';
        elseif ($len > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss')) $word = mb_substr($word, 0, -1);
        return self::length($word) > 6 ? mb_substr($word, 0, 6) : $word;
    }

    /* ── Privacy check ────────────────────────────────────────── */

    /** @return array{emails: int, phones: int} Rough counts of contact details in the text. */
    public static function privacyScan(string $text): array
    {
        $emails = preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text);
        $phones = preg_match_all('/(?<![\d-])(?:\+?\d{1,3}[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?![\d-])/', $text);
        return ['emails' => (int) $emails, 'phones' => (int) $phones];
    }
}
