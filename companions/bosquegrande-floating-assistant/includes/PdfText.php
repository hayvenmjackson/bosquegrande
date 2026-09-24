<?php

declare(strict_types=1);

namespace BGCompanion\Assistant;

if (!defined('ABSPATH')) exit;

/**
 * A small, dependency-free PDF text reader for training uploads.
 *
 * It walks the page tree in order, decodes Flate-compressed content and
 * object streams, and maps character codes to text through each font's
 * ToUnicode table, or through WinAnsi for simple fonts without one. It does
 * not render anything and ignores images. Encrypted PDFs are refused.
 */
final class PdfText
{
    private const STREAM_LIMIT = 20 * 1024 * 1024;
    private const TEXT_LIMIT = 1_000_000;
    private const TIME_LIMIT = 15.0;
    private const FORM_LIMIT = 2000;
    private const OBJECT_LIMIT = 100000;

    /** @var array<int, array{dict: string, stream: ?string}> */
    private array $objects = [];
    /** @var array<int, mixed> */
    private array $parsed = [];
    /** @var array<string, array{map: array<int, string>, bytes: list<int>, widths: array<int, float>, dw: float}|null> */
    private array $fonts = [];
    private int $length = 0;
    private float $started;
    private int $forms = 0;
    /** @var array<int, true> Form objects currently being drawn. */
    private array $activeForms = [];

    public function __construct(private string $data)
    {
        $this->started = microtime(true);
        $this->index();
    }

    /** Stop work on files built to take forever. */
    private function budget(): void
    {
        if (microtime(true) - $this->started > self::TIME_LIMIT) {
            throw new \RuntimeException('This PDF is too complex to read here. Open it, copy the text, and paste it instead.');
        }
    }

    /**
     * The first position in a sorted list at or after $from.
     *
     * @param list<int> $sorted
     */
    private static function after(array $sorted, int $from): ?int
    {
        $lo = 0;
        $hi = count($sorted);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($sorted[$mid] < $from) $lo = $mid + 1;
            else $hi = $mid;
        }
        return $sorted[$lo] ?? null;
    }

    public function text(): string
    {
        foreach ($this->objects as $num => $obj) {
            $dict = $this->object($num);
            if (is_array($dict) && (($dict['Type'] ?? '') === 'XRef') && isset($dict['Encrypt'])) {
                throw new \RuntimeException('This PDF is encrypted or password-protected. Save an unprotected copy, or paste the text instead.');
            }
        }
        if (preg_match('/\/Encrypt\s*(\d+\s+\d+\s+R|<<)/', $this->data)) {
            throw new \RuntimeException('This PDF is encrypted or password-protected. Save an unprotected copy, or paste the text instead.');
        }
        $pages = [];
        $catalog = $this->catalog();
        $seen = [];
        if ($catalog !== null) $this->collectPages($catalog['Pages'] ?? null, [], $pages, 0, $seen);
        $out = [];
        foreach ($pages as [$page, $resources]) {
            $text = trim($this->pageText($page, $resources));
            if ($text !== '') $out[] = $text;
            $this->length += strlen($text);
            if ($this->length > self::TEXT_LIMIT) break;
        }
        return implode("\n\n", $out);
    }

    /* ── Objects ───────────────────────────────────────────────── */

    private function index(): void
    {
        $headers = preg_match_all('/(?<![0-9])\d{1,10}\s+\d{1,5}\s+obj\b/', $this->data);
        if ($headers > self::OBJECT_LIMIT) {
            throw new \RuntimeException('This PDF has too many parts to read here. Open it, copy the text, and paste it instead.');
        }
        preg_match_all('/(?<![0-9])(\d{1,10})\s+(\d{1,5})\s+obj\b/', $this->data, $m, PREG_OFFSET_CAPTURE);
        $positions = static function (string $pattern, string $data): array {
            preg_match_all($pattern, $data, $found, PREG_OFFSET_CAPTURE);
            return array_map(static fn(array $hit): int => (int) $hit[1], $found[0]);
        };
        $endobjs = $positions('/endobj/', $this->data);
        $streams = $positions('/(?<!end)stream/', $this->data);
        $endstreams = $positions('/endstream/', $this->data);
        foreach ($m[0] as $i => $whole) {
            if ($i % 500 === 0) $this->budget();
            $num = (int) $m[1][$i][0];
            $start = $whole[1] + strlen($whole[0]);
            $endobj = self::after($endobjs, $start);
            if ($endobj === null) continue;
            $streamAt = self::after($streams, $start);
            $stream = null;
            if ($streamAt !== null && $streamAt < $endobj) {
                $dict = substr($this->data, $start, $streamAt - $start);
                $begin = $streamAt + 6;
                if (($this->data[$begin] ?? '') === "\r") $begin++;
                if (($this->data[$begin] ?? '') === "\n") $begin++;
                $len = preg_match('/\/Length\s+(\d+)(?!\s+\d+\s+R)/', $dict, $lm) ? (int) $lm[1] : -1;
                $after = $len >= 0 ? ltrim(substr($this->data, $begin + $len, 12)) : '';
                if ($len >= 0 && str_starts_with($after, 'endstream')) {
                    $stream = substr($this->data, $begin, $len);
                } else {
                    $es = self::after($endstreams, $begin);
                    if ($es === null) continue;
                    $stream = rtrim(substr($this->data, $begin, $es - $begin), "\r\n");
                }
                $this->objects[$num] = ['dict' => $dict, 'stream' => $stream];
            } else {
                $this->objects[$num] = ['dict' => substr($this->data, $start, $endobj - $start), 'stream' => null];
            }
        }
        // Objects packed into object streams (PDF 1.5 and later).
        foreach (array_keys($this->objects) as $num) {
            $dict = $this->object($num);
            if (!is_array($dict) || ($dict['Type'] ?? '') !== 'ObjStm') continue;
            $raw = $this->streamData($num);
            if ($raw === null) continue;
            $first = (int) ($dict['First'] ?? 0);
            $count = (int) ($dict['N'] ?? 0);
            $header = preg_split('/\s+/', trim(substr($raw, 0, $first))) ?: [];
            $entries = [];
            for ($k = 0; $k + 1 < count($header) && count($entries) < $count; $k += 2) {
                $entries[] = [(int) $header[$k], (int) $header[$k + 1]];
            }
            foreach ($entries as $e => [$objNum, $offset]) {
                $next = isset($entries[$e + 1]) ? $entries[$e + 1][1] : strlen($raw) - $first;
                if (!isset($this->objects[$objNum])) {
                    $this->objects[$objNum] = ['dict' => substr($raw, $first + $offset, $next - $offset), 'stream' => null];
                }
            }
        }
    }

    /** The parsed dictionary (or value) of an object. */
    private function object(int $num): mixed
    {
        if (array_key_exists($num, $this->parsed)) return $this->parsed[$num];
        $this->parsed[$num] = null;
        if (!isset($this->objects[$num])) return null;
        $i = 0;
        return $this->parsed[$num] = PdfParser::value($this->objects[$num]['dict'], $i);
    }

    private function resolve(mixed $value, int $depth = 0): mixed
    {
        while ($value instanceof PdfRef && $depth++ < 32) $value = $this->object($value->num);
        return $value;
    }

    private function streamData(int $num): ?string
    {
        $obj = $this->objects[$num] ?? null;
        if ($obj === null || $obj['stream'] === null) return null;
        $dict = $this->object($num);
        $filters = is_array($dict) ? $this->resolve($dict['Filter'] ?? null) : null;
        $filters = $filters === null ? [] : (is_array($filters) ? $filters : [$filters]);
        $data = $obj['stream'];
        foreach ($filters as $filter) {
            $filter = $this->resolve($filter);
            if ($filter === 'FlateDecode' || $filter === 'Fl') {
                $decoded = @gzuncompress($data, self::STREAM_LIMIT);
                if ($decoded === false) $decoded = @gzinflate(substr($data, 2), self::STREAM_LIMIT);
                if ($decoded === false) return null;
                $data = $decoded;
            } elseif ($filter === 'ASCII85Decode' || $filter === 'A85') {
                $data = self::ascii85($data);
            } elseif ($filter === 'ASCIIHexDecode' || $filter === 'AHx') {
                $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', strstr($data, '>', true) ?: $data);
                $data = (string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex);
            } else {
                return null;
            }
        }
        return $data;
    }

    private static function ascii85(string $data): string
    {
        $end = strpos($data, '~>');
        if ($end !== false) $data = substr($data, 0, $end);
        if (str_starts_with($data, '<~')) $data = substr($data, 2);
        $data = (string) preg_replace('/\s+/', '', $data);
        $out = '';
        $group = [];
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $c = $data[$i];
            if ($c === 'z' && !$group) {
                $out .= "\0\0\0\0";
                continue;
            }
            $v = ord($c) - 33;
            if ($v < 0 || $v > 84) continue;
            $group[] = $v;
            if (count($group) === 5) {
                $num = 0;
                foreach ($group as $d) $num = $num * 85 + $d;
                $out .= pack('N', $num & 0xFFFFFFFF);
                $group = [];
            }
        }
        if ($group) {
            $pad = 5 - count($group);
            $group = array_pad($group, 5, 84);
            $num = 0;
            foreach ($group as $d) $num = $num * 85 + $d;
            $out .= substr(pack('N', $num & 0xFFFFFFFF), 0, 4 - $pad);
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function catalog(): ?array
    {
        $catalog = null;
        foreach (array_keys($this->objects) as $num) {
            $dict = $this->object($num);
            if (is_array($dict) && ($dict['Type'] ?? '') === 'Catalog') $catalog = $dict;
        }
        return $catalog;
    }

    /**
     * @param array<string, mixed> $inherited
     * @param list<array{0: array<mixed>, 1: array<mixed>}> $pages
     * @param array<int, true> $seen Page-tree objects already visited, so loops end.
     */
    private function collectPages(mixed $node, array $inherited, array &$pages, int $depth, array &$seen): void
    {
        if ($node instanceof PdfRef) {
            if (isset($seen[$node->num])) return;
            $seen[$node->num] = true;
            $node = $this->resolve($node);
        }
        if (!is_array($node) || $depth > 64 || count($pages) > 5000) return;
        $this->budget();
        $resources = $this->resolve($node['Resources'] ?? null);
        if (is_array($resources)) $inherited = $resources;
        if (($node['Type'] ?? '') === 'Page' || (!isset($node['Kids']) && isset($node['Contents']))) {
            $pages[] = [$node, $inherited];
            return;
        }
        $kids = $this->resolve($node['Kids'] ?? null);
        if (!is_array($kids)) return;
        foreach ($kids as $kid) $this->collectPages($kid, $inherited, $pages, $depth + 1, $seen);
    }

    /* ── Pages ─────────────────────────────────────────────────── */

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $resources
     */
    private function pageText(array $page, array $resources): string
    {
        $contents = $page['Contents'] ?? null;
        $refs = $contents instanceof PdfRef ? [$contents] : (is_array($contents) ? $contents : []);
        $resolvedContents = $this->resolve($contents);
        if (is_array($resolvedContents) && !isset($resolvedContents['Length']) && $resolvedContents === array_values($resolvedContents)) {
            $refs = $resolvedContents;
        }
        $stream = '';
        foreach ($refs as $ref) {
            if ($ref instanceof PdfRef) $stream .= ($this->streamData($ref->num) ?? '') . "\n";
        }
        return $this->runContent($stream, $resources, 0);
    }

    /**
     * Run a content stream, tracking the text matrix and each glyph's
     * advance so that spaces go where the page shows a gap and nowhere else.
     *
     * @param array<string, mixed> $resources
     */
    private function runContent(string $stream, array $resources, int $depth): string
    {
        $fonts = $this->resolve($resources['Font'] ?? null);
        $fonts = is_array($fonts) ? $fonts : [];
        $xobjects = $this->resolve($resources['XObject'] ?? null);
        $xobjects = is_array($xobjects) ? $xobjects : [];

        $out = '';
        // Text state (saved and restored with q and Q).
        $st = ['font' => null, 'size' => 10.0, 'tc' => 0.0, 'tw' => 0.0, 'th' => 1.0, 'tl' => 0.0];
        $stack = [];
        $tm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $tlm = $tm;
        $pen = null; // [x, y] where the previous text ended, in the same space as $tm.
        $operands = [];

        $newline = function () use (&$out): void {
            if ($out !== '' && !str_ends_with($out, "\n")) $out .= "\n";
        };
        $space = function () use (&$out): void {
            if ($out !== '' && !preg_match('/\s$/', $out)) $out .= ' ';
        };
        $em = function () use (&$st, &$tm): float {
            $scale = sqrt($tm[2] * $tm[2] + $tm[3] * $tm[3]) ?: 1.0;
            return max(0.5, abs($st['size']) * $scale);
        };
        $translate = function (float $tx, float $ty, array $m): array {
            return [$m[0], $m[1], $m[2], $m[3], $tx * $m[0] + $ty * $m[2] + $m[4], $tx * $m[1] + $ty * $m[3] + $m[5]];
        };
        // Before showing text: compare where it starts with where the last text ended.
        $place = function () use (&$pen, &$tm, $em, $newline, $space): void {
            if ($pen === null) return;
            $size = $em();
            $dy = $tm[5] - $pen[1];
            $dx = $tm[4] - $pen[0];
            if (abs($dy) > $size * 0.5) $newline();
            elseif ($dx > $size * 0.2 || $dx < -$size * 1.5) $space();
        };
        $show = function (string $bytes) use (&$st, &$tm, &$out, $translate): void {
            [$text, $advance, $spaces, $glyphs] = $this->decode($bytes, $st['font']);
            $out .= $text;
            $tx = ($advance * $st['size'] + $glyphs * $st['tc'] + $spaces * $st['tw']) * $st['th'];
            $tm = $translate($tx, 0.0, $tm);
        };

        $i = 0;
        $steps = 0;
        $n = strlen($stream);
        while ($i < $n) {
            $token = PdfParser::token($stream, $i);
            if ($token === null) break;
            if (++$steps % 5000 === 0) $this->budget();
            if (!$token['op']) {
                $operands[] = $token['value'];
                if (count($operands) > 64) array_shift($operands);
                continue;
            }
            $op = $token['value'];
            $num = static fn(int $back): float => (float) (is_numeric($operands[count($operands) - $back] ?? null) ? $operands[count($operands) - $back] : 0);
            switch ($op) {
                case 'q':
                    $stack[] = $st;
                    break;
                case 'Q':
                    if ($stack) $st = array_pop($stack);
                    break;
                case 'BT':
                    $tm = $tlm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
                    break;
                case 'Tf':
                    $name = $operands[count($operands) - 2] ?? null;
                    $st['size'] = $num(1);
                    $st['font'] = is_string($name) ? $this->font($fonts[$name] ?? null, $name) : null;
                    break;
                case 'Tc':
                    $st['tc'] = $num(1);
                    break;
                case 'Tw':
                    $st['tw'] = $num(1);
                    break;
                case 'Tz':
                    $st['th'] = $num(1) / 100;
                    break;
                case 'TL':
                    $st['tl'] = $num(1);
                    break;
                case 'Td':
                case 'TD':
                    if ($op === 'TD') $st['tl'] = -$num(1);
                    $tm = $tlm = $translate($num(2), $num(1), $tlm);
                    break;
                case 'Tm':
                    $tm = $tlm = [$num(6), $num(5), $num(4), $num(3), $num(2), $num(1)];
                    break;
                case 'T*':
                    $tm = $tlm = $translate(0.0, -$st['tl'], $tlm);
                    break;
                case 'Tj':
                case "'":
                case '"':
                    if ($op === '"') {
                        $st['tw'] = $num(3);
                        $st['tc'] = $num(2);
                    }
                    if ($op !== 'Tj') $tm = $tlm = $translate(0.0, -$st['tl'], $tlm);
                    $str = end($operands);
                    if ($str instanceof PdfStr) {
                        $place();
                        $show($str->bytes);
                        $pen = [$tm[4], $tm[5]];
                    }
                    break;
                case 'TJ':
                    $arr = end($operands);
                    if (!is_array($arr)) break;
                    $place();
                    foreach ($arr as $part) {
                        if ($part instanceof PdfStr) {
                            $show($part->bytes);
                        } elseif (is_numeric($part)) {
                            // Negative numbers move right; a big move is a word gap.
                            if ((float) $part < -200) $space();
                            $tm = $translate(-(float) $part / 1000 * $st['size'] * $st['th'], 0.0, $tm);
                        }
                    }
                    $pen = [$tm[4], $tm[5]];
                    break;
                case 'Do':
                    $name = end($operands);
                    $xobject = is_string($name) ? ($xobjects[$name] ?? null) : null;
                    if ($xobject instanceof PdfRef && $depth < 4 && !isset($this->activeForms[$xobject->num]) && ++$this->forms <= self::FORM_LIMIT) {
                        $this->activeForms[$xobject->num] = true;
                        $form = $this->object($xobject->num);
                        if (is_array($form) && ($form['Subtype'] ?? '') === 'Form') {
                            $formResources = $this->resolve($form['Resources'] ?? null);
                            $inner = $this->streamData($xobject->num);
                            if ($inner !== null) {
                                $newline();
                                $out .= $this->runContent($inner, is_array($formResources) ? $formResources : $resources, $depth + 1);
                                $newline();
                                $pen = null;
                            }
                        }
                        unset($this->activeForms[$xobject->num]);
                    }
                    break;
                case 'BI':
                    // Inline image: skip its binary data up to the EI marker.
                    $i = preg_match('/\sEI(?=\s|$)/', $stream, $mm, PREG_OFFSET_CAPTURE, $i) ? $mm[0][1] + 3 : $n;
                    break;
            }
            $operands = [];
            if (strlen($out) > self::TEXT_LIMIT) break;
        }
        return $out;
    }

    /* ── Fonts ─────────────────────────────────────────────────── */

    /**
     * A font's code-to-text map, code byte lengths, and glyph widths.
     *
     * @return array{map: array<int, string>, bytes: list<int>, widths: array<int, float>, dw: float}|null
     */
    private function font(mixed $ref, string $name): ?array
    {
        $key = $ref instanceof PdfRef ? 'r' . $ref->num : 'n' . $name;
        if (array_key_exists($key, $this->fonts)) return $this->fonts[$key];
        $dict = $this->resolve($ref);
        if (!is_array($dict)) return $this->fonts[$key] = null;

        $composite = ($dict['Subtype'] ?? '') === 'Type0';
        $map = [];
        $bytes = $composite ? [2] : [1];
        $toUnicode = $dict['ToUnicode'] ?? null;
        if ($toUnicode instanceof PdfRef) {
            $cmap = $this->streamData($toUnicode->num);
            if ($cmap !== null) [$map, $cmapBytes] = self::parseCMap($cmap);
            if ($composite && !empty($cmapBytes)) $bytes = $cmapBytes;
        }
        if (!$composite) {
            // Simple fonts: fill gaps in the ToUnicode table from the encoding.
            $base = self::winAnsi();
            $encoding = $this->resolve($dict['Encoding'] ?? null);
            if (is_array($encoding)) {
                $differences = $this->resolve($encoding['Differences'] ?? null);
                if (is_array($differences)) {
                    $code = 0;
                    foreach ($differences as $item) {
                        if (is_int($item) || is_float($item)) {
                            $code = (int) $item;
                        } elseif (is_string($item)) {
                            $char = Glyphs::toUnicode($item);
                            if ($char !== null) $base[$code] = $char;
                            $code++;
                        }
                    }
                }
            }
            $map += $base;
        }

        $widths = [];
        $dw = $composite ? 1000.0 : 500.0;
        if ($composite) {
            $descendants = $this->resolve($dict['DescendantFonts'] ?? null);
            $cid = is_array($descendants) ? $this->resolve($descendants[0] ?? null) : null;
            if (is_array($cid)) {
                $dw = (float) ($this->resolve($cid['DW'] ?? null) ?? 1000);
                $w = $this->resolve($cid['W'] ?? null);
                if (is_array($w)) {
                    for ($k = 0, $c = count($w); $k < $c;) {
                        $first = (int) $this->resolve($w[$k]);
                        $next = $this->resolve($w[$k + 1] ?? null);
                        if (is_array($next)) {
                            foreach ($next as $j => $width) $widths[$first + $j] = (float) $this->resolve($width);
                            $k += 2;
                        } else {
                            $last = min((int) $next, $first + 65535);
                            $width = (float) $this->resolve($w[$k + 2] ?? 0);
                            for ($code = $first; $code <= $last; $code++) $widths[$code] = $width;
                            $k += 3;
                        }
                    }
                }
            }
        } else {
            $first = (int) ($this->resolve($dict['FirstChar'] ?? null) ?? 0);
            $list = $this->resolve($dict['Widths'] ?? null);
            if (is_array($list)) {
                foreach ($list as $j => $width) $widths[$first + $j] = (float) $this->resolve($width);
            }
            $descriptor = $this->resolve($dict['FontDescriptor'] ?? null);
            if (is_array($descriptor) && isset($descriptor['MissingWidth'])) $dw = (float) $this->resolve($descriptor['MissingWidth']);
        }
        if ($composite && !$map) $map = [];
        return $this->fonts[$key] = ['map' => $map, 'bytes' => $bytes, 'widths' => $widths, 'dw' => $dw];
    }

    /**
     * Decode a shown string: its text, total glyph advance in em units,
     * how many single-byte spaces it had (for word spacing), and its glyph count.
     *
     * @param array{map: array<int, string>, bytes: list<int>, widths: array<int, float>, dw: float}|null $font
     * @return array{0: string, 1: float, 2: int, 3: int}
     */
    private function decode(string $bytes, ?array $font): array
    {
        if ($font === null) {
            $text = self::latin($bytes);
            return [$text, strlen($bytes) * 0.5, substr_count($bytes, ' '), strlen($bytes)];
        }
        $out = '';
        $advance = 0.0;
        $spaces = 0;
        $glyphs = 0;
        $n = strlen($bytes);
        $i = 0;
        while ($i < $n) {
            $width = $font['bytes'][0];
            foreach ($font['bytes'] as $w) {
                if ($i + $w > $n) continue;
                $code = 0;
                for ($k = 0; $k < $w; $k++) $code = ($code << 8) | ord($bytes[$i + $k]);
                if (isset($font['map'][$code]) || isset($font['widths'][$code])) {
                    $width = $w;
                    break;
                }
            }
            $code = 0;
            for ($k = 0; $k < $width && $i + $k < $n; $k++) $code = ($code << 8) | ord($bytes[$i + $k]);
            $out .= $font['map'][$code] ?? '';
            $advance += ($font['widths'][$code] ?? $font['dw']) / 1000;
            if ($width === 1 && $code === 32) $spaces++;
            $glyphs++;
            $i += $width;
        }
        return [$out, $advance, $spaces, $glyphs];
    }

    /**
     * Read a ToUnicode CMap: code widths from codespacerange, then bfchar
     * and bfrange entries. Targets are UTF-16BE.
     *
     * @return array{0: array<int, string>, 1: list<int>}
     */
    public static function parseCMap(string $cmap): array
    {
        $widths = [];
        if (preg_match_all('/begincodespacerange(.*?)endcodespacerange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $ranges, PREG_SET_ORDER)) {
                    foreach ($ranges as $r) $widths[] = intdiv(strlen($r[1]) + 1, 2);
                }
            }
        }
        $map = [];
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $block, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $p) {
                        $map[(int) hexdec($p[1])] = self::utf16($p[2]);
                        $widths[] = intdiv(strlen($p[1]) + 1, 2);
                    }
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (!preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $block, $ranges, PREG_SET_ORDER)) continue;
                foreach ($ranges as $r) {
                    $lo = (int) hexdec($r[1]);
                    $hi = min((int) hexdec($r[2]), $lo + 65535);
                    $widths[] = intdiv(strlen($r[1]) + 1, 2);
                    if ($r[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]*)>/', $r[3], $dsts);
                        foreach ($dsts[1] as $k => $dst) {
                            if ($lo + $k > $hi) break;
                            $map[$lo + $k] = self::utf16($dst);
                        }
                    } else {
                        $dst = substr($r[3], 1, -1);
                        $base = (int) hexdec($dst === '' ? '0' : $dst);
                        $prefix = strlen($dst) > 4 ? substr($dst, 0, -4) : '';
                        for ($code = $lo; $code <= $hi; $code++) {
                            $unit = $prefix === ''
                                ? sprintf('%04X', ($base + $code - $lo) & 0xFFFF)
                                : $prefix . sprintf('%04X', (hexdec(substr($dst, -4)) + $code - $lo) & 0xFFFF);
                            $map[$code] = self::utf16($unit);
                        }
                    }
                }
            }
        }
        $widths = array_values(array_unique(array_filter($widths, static fn(int $w): bool => $w >= 1 && $w <= 4)));
        rsort($widths);
        return [$map, $widths ?: [1]];
    }

    private static function utf16(string $hex): string
    {
        if ($hex === '') return '';
        if (strlen($hex) % 4 !== 0) $hex = str_pad($hex, (int) (ceil(strlen($hex) / 4) * 4), '0', STR_PAD_LEFT);
        $bytes = (string) hex2bin($hex);
        $text = function_exists('mb_convert_encoding') ? @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE') : false;
        if (!is_string($text) && function_exists('iconv')) $text = @iconv('UTF-16BE', 'UTF-8//IGNORE', $bytes);
        return is_string($text) ? $text : '';
    }

    private static function latin(string $bytes): string
    {
        $map = self::winAnsi();
        $out = '';
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) $out .= $map[ord($bytes[$i])] ?? '';
        return $out;
    }

    /** @return array<int, string> */
    private static function winAnsi(): array
    {
        static $map = null;
        if ($map !== null) return $map;
        $map = [];
        for ($c = 32; $c < 127; $c++) $map[$c] = chr($c);
        for ($c = 160; $c < 256; $c++) $map[$c] = Glyphs::chr($c);
        $extra = [
            128 => 0x20AC, 130 => 0x201A, 131 => 0x0192, 132 => 0x201E, 133 => 0x2026, 134 => 0x2020, 135 => 0x2021,
            136 => 0x02C6, 137 => 0x2030, 138 => 0x0160, 139 => 0x2039, 140 => 0x0152, 142 => 0x017D, 145 => 0x2018,
            146 => 0x2019, 147 => 0x201C, 148 => 0x201D, 149 => 0x2022, 150 => 0x2013, 151 => 0x2014, 152 => 0x02DC,
            153 => 0x2122, 154 => 0x0161, 155 => 0x203A, 156 => 0x0153, 158 => 0x017E, 159 => 0x0178,
        ];
        foreach ($extra as $code => $cp) $map[$code] = Glyphs::chr($cp);
        $map[9] = "\t";
        $map[10] = "\n";
        $map[13] = "\n";
        return $map;
    }
}

/** A reference to an indirect object. */
final class PdfRef
{
    public function __construct(public int $num)
    {
    }
}

/** A PDF string, kept as raw bytes until a font decodes it. */
final class PdfStr
{
    public function __construct(public string $bytes)
    {
    }
}

/**
 * Tokenizer and value parser for PDF object syntax and content streams.
 * Names come back as plain PHP strings (without the slash), strings as
 * PdfStr, references as PdfRef, dictionaries as string-keyed arrays.
 */
final class PdfParser
{
    private const DELIMS = "()<>[]{}/%";
    private const SPACE = " \t\r\n\f\0";
    private const MAX_DEPTH = 64;
    private static int $depth = 0;

    private static function enter(): void
    {
        if (++self::$depth > self::MAX_DEPTH) {
            self::$depth = 0;
            throw new \RuntimeException('This PDF is nested too deeply to read. Open it, copy the text, and paste it instead.');
        }
    }

    public static function value(string $s, int &$i): mixed
    {
        $token = self::token($s, $i);
        if ($token === null) return null;
        $value = $token['value'];
        if ($token['op']) {
            return match ($value) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }
        // Integer pair followed by R is a reference.
        if (is_int($value)) {
            $save = $i;
            $second = self::token($s, $i);
            if ($second !== null && is_int($second['value']) && !$second['op']) {
                $third = self::token($s, $i);
                if ($third !== null && $third['op'] && $third['value'] === 'R') return new PdfRef($value);
            }
            $i = $save;
        }
        return $value;
    }

    /** @return array{op: bool, value: mixed}|null */
    public static function token(string $s, int &$i): ?array
    {
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if (strpos(self::SPACE, $c) !== false) {
                $i++;
            } elseif ($c === '%') {
                while ($i < $n && $s[$i] !== "\n" && $s[$i] !== "\r") $i++;
            } else {
                break;
            }
        }
        if ($i >= $n) return null;
        $c = $s[$i];

        if ($c === '/') {
            $i++;
            $start = $i;
            while ($i < $n && strpos(self::SPACE . self::DELIMS, $s[$i]) === false) $i++;
            $name = substr($s, $start, $i - $start);
            $name = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn($m) => chr((int) hexdec($m[1])), $name);
            return ['op' => false, 'value' => $name];
        }
        if ($c === '(') return ['op' => false, 'value' => new PdfStr(self::literal($s, $i))];
        if ($c === '<' && ($s[$i + 1] ?? '') === '<') {
            $i += 2;
            self::enter();
            $dict = [];
            while ($i < $n) {
                $save = $i;
                $key = self::token($s, $i);
                if ($key === null) break;
                if ($key['op'] && $key['value'] === '>>') break;
                if ($key['op'] || !is_string($key['value'])) {
                    if ($i === $save) $i++;
                    continue;
                }
                $dict[$key['value']] = self::value($s, $i);
            }
            self::$depth--;
            return ['op' => false, 'value' => $dict];
        }
        if ($c === '<') {
            $end = strpos($s, '>', $i);
            if ($end === false) $end = $n;
            $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, $end - $i - 1));
            $i = $end + 1;
            if (strlen($hex) % 2) $hex .= '0';
            return ['op' => false, 'value' => new PdfStr((string) hex2bin($hex))];
        }
        if ($c === '>' && ($s[$i + 1] ?? '') === '>') {
            $i += 2;
            return ['op' => true, 'value' => '>>'];
        }
        if ($c === '[') {
            $i++;
            self::enter();
            $arr = [];
            while ($i < $n) {
                $save = $i;
                $peek = self::token($s, $i);
                if ($peek === null) break;
                if ($peek['op'] && $peek['value'] === ']') break;
                $i = $save;
                $value = self::value($s, $i);
                if ($i === $save) {
                    $i++;
                    continue;
                }
                $arr[] = $value;
            }
            self::$depth--;
            return ['op' => false, 'value' => $arr];
        }
        if ($c === ']' || $c === '{' || $c === '}' || $c === ')' || $c === '>') {
            $i++;
            return ['op' => true, 'value' => $c];
        }
        $start = $i;
        while ($i < $n && strpos(self::SPACE . self::DELIMS, $s[$i]) === false) $i++;
        $word = substr($s, $start, $i - $start);
        if ($word === '') {
            $i++;
            return ['op' => true, 'value' => $c];
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)$/', $word)) {
            return ['op' => false, 'value' => str_contains($word, '.') ? (float) $word : (int) $word];
        }
        return ['op' => true, 'value' => $word];
    }

    private static function literal(string $s, int &$i): string
    {
        $n = strlen($s);
        $i++;
        $depth = 1;
        $out = '';
        while ($i < $n) {
            $c = $s[$i];
            if ($c === '\\') {
                $next = $s[$i + 1] ?? '';
                $i += 2;
                switch ($next) {
                    case 'n': $out .= "\n"; break;
                    case 'r': $out .= "\r"; break;
                    case 't': $out .= "\t"; break;
                    case 'b': $out .= "\x08"; break;
                    case 'f': $out .= "\f"; break;
                    case "\r":
                        if (($s[$i] ?? '') === "\n") $i++;
                        break;
                    case "\n":
                        break;
                    default:
                        if ($next !== '' && ctype_digit($next) && $next < '8') {
                            $oct = $next;
                            while (strlen($oct) < 3 && isset($s[$i]) && $s[$i] >= '0' && $s[$i] <= '7') $oct .= $s[$i++];
                            $out .= chr(octdec($oct) & 0xFF);
                        } else {
                            $out .= $next;
                        }
                }
                continue;
            }
            if ($c === '(') $depth++;
            if ($c === ')' && --$depth === 0) {
                $i++;
                break;
            }
            $out .= $c;
            $i++;
        }
        return $out;
    }
}

/** Glyph names used in /Differences arrays, mapped to text. */
final class Glyphs
{
    private const NAMES = [
        'space' => ' ', 'exclam' => '!', 'quotedbl' => '"', 'numbersign' => '#', 'dollar' => '$', 'percent' => '%',
        'ampersand' => '&', 'quotesingle' => "'", 'quoteright' => "\u{2019}", 'quoteleft' => "\u{2018}",
        'parenleft' => '(', 'parenright' => ')', 'asterisk' => '*', 'plus' => '+', 'comma' => ',', 'hyphen' => '-',
        'minus' => '-', 'period' => '.', 'slash' => '/', 'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3',
        'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'colon' => ':',
        'semicolon' => ';', 'less' => '<', 'equal' => '=', 'greater' => '>', 'question' => '?', 'at' => '@',
        'bracketleft' => '[', 'backslash' => '\\', 'bracketright' => ']', 'asciicircum' => '^', 'underscore' => '_',
        'grave' => '`', 'braceleft' => '{', 'bar' => '|', 'braceright' => '}', 'asciitilde' => '~',
        'quotedblleft' => "\u{201C}", 'quotedblright' => "\u{201D}", 'quotesinglbase' => "\u{201A}",
        'quotedblbase' => "\u{201E}", 'endash' => "\u{2013}", 'emdash' => "\u{2014}", 'bullet' => "\u{2022}",
        'ellipsis' => "\u{2026}", 'fi' => 'fi', 'fl' => 'fl', 'ff' => 'ff', 'ffi' => 'ffi', 'ffl' => 'ffl',
        'copyright' => "\u{00A9}", 'registered' => "\u{00AE}", 'trademark' => "\u{2122}", 'degree' => "\u{00B0}",
        'nbspace' => ' ', 'nonbreakingspace' => ' ', 'section' => "\u{00A7}", 'paragraph' => "\u{00B6}",
        'dagger' => "\u{2020}", 'daggerdbl' => "\u{2021}", 'Euro' => "\u{20AC}", 'sterling' => "\u{00A3}",
        'yen' => "\u{00A5}", 'cent' => "\u{00A2}", 'eacute' => "\u{00E9}", 'egrave' => "\u{00E8}", 'aacute' => "\u{00E1}",
        'agrave' => "\u{00E0}", 'iacute' => "\u{00ED}", 'oacute' => "\u{00F3}", 'uacute' => "\u{00FA}",
        'ntilde' => "\u{00F1}", 'ccedilla' => "\u{00E7}", 'udieresis' => "\u{00FC}", 'odieresis' => "\u{00F6}",
        'adieresis' => "\u{00E4}", 'germandbls' => "\u{00DF}",
    ];

    public static function toUnicode(string $name): ?string
    {
        if (isset(self::NAMES[$name])) return self::NAMES[$name];
        if (preg_match('/^[A-Za-z]$/', $name)) return $name;
        if (preg_match('/^uni([0-9A-Fa-f]{4})$/', $name, $m)) return self::chr((int) hexdec($m[1]));
        if (preg_match('/^u([0-9A-Fa-f]{4,6})$/', $name, $m)) return self::chr((int) hexdec($m[1]));
        $base = strstr($name, '.', true);
        if ($base !== false && $base !== '') return self::toUnicode($base);
        return null;
    }

    /** UTF-8 for one code point, without relying on mbstring. */
    public static function chr(int $cp): string
    {
        if ($cp < 0 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) return '';
        if ($cp < 0x80) return chr($cp);
        if ($cp < 0x800) return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        if ($cp < 0x10000) return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }
}
