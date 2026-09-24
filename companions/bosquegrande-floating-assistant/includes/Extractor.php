<?php

declare(strict_types=1);

namespace BGCompanion\Assistant;

if (!defined('ABSPATH')) exit;

/**
 * Turns an uploaded file into plain text. The uploaded file itself is never
 * kept; only the text is stored.
 *
 * Plain text, Markdown, CSV, and HTML are read directly. Word (.docx) and
 * OpenDocument (.odt) files are read from their XML. PDF text is extracted
 * on a best-effort basis: text from most word processors and browsers comes
 * out cleanly, but scanned pages have no text to extract and some PDFs use
 * font encodings that cannot be mapped back to letters. Those are refused
 * with a request to paste the text instead, rather than stored as gibberish.
 */
final class Extractor
{
    public const TYPES = ['txt', 'md', 'markdown', 'csv', 'html', 'htm', 'docx', 'odt', 'pdf'];
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** @return array{ok: bool, text: string, error: string} */
    public static function fromFile(string $path, string $originalName): array
    {
        $type = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($type, self::TYPES, true)) {
            return self::fail('That file type is not supported. Use .txt, .md, .csv, .html, .docx, .odt, or .pdf.');
        }
        $size = @filesize($path);
        if ($size === false || $size === 0) return self::fail('The file is empty or could not be read.');
        if ($size > self::MAX_BYTES) return self::fail('The file is larger than 10 MB. Split it or paste the text instead.');

        switch ($type) {
            case 'docx':
                return self::docx($path);
            case 'odt':
                return self::odt($path);
            case 'pdf':
                return self::pdf((string) file_get_contents($path));
            case 'html':
            case 'htm':
                return self::done(self::htmlToText(self::toUtf8((string) file_get_contents($path))));
            default:
                return self::done(self::toUtf8((string) file_get_contents($path)));
        }
    }

    /** Normalize pasted or extracted text: UTF-8, Unix line ends, no control characters, tidy blank lines. */
    public static function clean(string $text): string
    {
        $text = self::toUtf8($text);
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{FEFF}"], ["\n", "\n", ' ', ''], $text);
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text);
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    public static function toUtf8(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $from = str_starts_with($text, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $converted = function_exists('mb_convert_encoding') ? @mb_convert_encoding(substr($text, 2), 'UTF-8', $from) : false;
            return is_string($converted) ? $converted : '';
        }
        $valid = function_exists('mb_check_encoding') ? mb_check_encoding($text, 'UTF-8') : (bool) preg_match('//u', $text);
        if ($valid) return $text;
        $converted = function_exists('mb_convert_encoding') ? @mb_convert_encoding($text, 'UTF-8', 'Windows-1252') : false;
        if (!is_string($converted) && function_exists('iconv')) $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $text);
        return is_string($converted) ? $converted : '';
    }

    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<h([1-6])\b[^>]*>#i', "\n\n# ", $html);
        $html = (string) preg_replace('#<li\b[^>]*>#i', "\n- ", $html);
        $html = (string) preg_replace('#<(br|hr)\b[^>]*>#i', "\n", $html);
        $html = (string) preg_replace('#</?(p|div|section|article|header|footer|h[1-6]|ul|ol|table|tr|blockquote|pre|main|aside|nav)\b[^>]*>#i', "\n\n", $html);
        $html = (string) preg_replace('#</t[dh]>#i', ' | ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return (string) preg_replace('/[ \t]{2,}/', ' ', $text);
    }

    /* ── Word and OpenDocument ────────────────────────────────────── */

    /** Largest XML part read from a .docx or .odt (ordinary documents are far smaller). */
    private const XML_LIMIT = 8 * 1024 * 1024;
    /** Most XML nodes read from one document. */
    private const NODE_LIMIT = 2_000_000;

    /**
     * Word text, read as a stream so memory stays flat however the file is
     * built. Documents that declare a DOCTYPE are refused: real Word and
     * OpenDocument files never do, and entity tricks live there.
     *
     * @return array{ok: bool, text: string, error: string}
     */
    private static function docx(string $path): array
    {
        $xml = self::zipEntry($path, 'word/document.xml');
        if ($xml === null) return self::fail(self::zipError());
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $reader = self::reader($xml);
        if (!$reader) return self::fail('The Word file could not be read. Save it again, or paste the text instead.');
        $out = [];
        $para = '';
        $style = '';
        $isList = false;
        $cellDepth = 0;
        $cellParts = [];
        $row = [];
        $nodes = 0;
        $endParagraph = function () use (&$para, &$style, &$isList, &$out, &$cellDepth, &$cellParts): void {
            $text = $para;
            if ($text !== '' && (str_starts_with($style, 'heading') || $style === 'title')) $text = "\n# " . $text;
            if ($text !== '' && $isList) $text = '- ' . $text;
            if ($cellDepth > 0) {
                if (trim($text) !== '') $cellParts[] = trim($text);
            } else {
                $out[] = $text;
            }
            $para = '';
            $style = '';
            $isList = false;
        };
        while (@$reader->read()) {
            if (++$nodes > self::NODE_LIMIT) return self::fail('The Word file is too large or too complex to read. Split it, or paste the text instead.');
            if ($reader->nodeType === \XMLReader::DOC_TYPE) return self::fail('The Word file could not be read. Save it again, or paste the text instead.');
            if ($reader->namespaceURI !== $w) continue;
            $name = $reader->localName;
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                switch ($name) {
                    case 'p':
                        $para = '';
                        $style = '';
                        $isList = false;
                        if ($reader->isEmptyElement) $endParagraph();
                        break;
                    case 'pStyle':
                        $style = strtolower((string) $reader->getAttributeNs('val', $w));
                        break;
                    case 'numPr':
                        $isList = true;
                        break;
                    case 't':
                        if (!$reader->isEmptyElement) $para .= $reader->readString();
                        break;
                    case 'tab':
                        $para .= "\t";
                        break;
                    case 'br':
                    case 'cr':
                        $para .= "\n";
                        break;
                    case 'tr':
                        if ($cellDepth === 0) $row = [];
                        break;
                    case 'tc':
                        if ($cellDepth++ === 0) $cellParts = [];
                        break;
                }
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT) {
                switch ($name) {
                    case 'p':
                        $endParagraph();
                        break;
                    case 'tc':
                        if (--$cellDepth === 0) $row[] = implode(' ', $cellParts);
                        $cellDepth = max(0, $cellDepth);
                        break;
                    case 'tr':
                        if ($cellDepth === 0) $out[] = implode(' | ', $row);
                        break;
                    case 'tbl':
                        if ($cellDepth === 0) $out[] = '';
                        break;
                }
            }
        }
        $reader->close();
        return self::done(implode("\n", $out));
    }

    /** @return array{ok: bool, text: string, error: string} */
    private static function odt(string $path): array
    {
        $xml = self::zipEntry($path, 'content.xml');
        if ($xml === null) return self::fail(self::zipError());
        $text = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
        $table = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
        $reader = self::reader($xml);
        if (!$reader) return self::fail('The OpenDocument file could not be read. Save it again, or paste the text instead.');
        $out = [];
        $line = '';
        $inBlock = 0;
        $heading = false;
        $cellDepth = 0;
        $cellParts = [];
        $row = [];
        $nodes = 0;
        while (@$reader->read()) {
            if (++$nodes > self::NODE_LIMIT) return self::fail('The OpenDocument file is too large or too complex to read. Split it, or paste the text instead.');
            $type = $reader->nodeType;
            if ($type === \XMLReader::DOC_TYPE) return self::fail('The OpenDocument file could not be read. Save it again, or paste the text instead.');
            if (($type === \XMLReader::TEXT || $type === \XMLReader::SIGNIFICANT_WHITESPACE || $type === \XMLReader::CDATA) && $inBlock > 0) {
                $line .= $reader->value;
                continue;
            }
            $ns = $reader->namespaceURI;
            $name = $reader->localName;
            if ($type === \XMLReader::ELEMENT) {
                if ($ns === $text && ($name === 'p' || $name === 'h')) {
                    if ($inBlock === 0) {
                        $line = '';
                        $heading = $name === 'h';
                    }
                    if ($reader->isEmptyElement) {
                        if ($inBlock === 0 && $cellDepth === 0) $out[] = '';
                    } else {
                        $inBlock++;
                    }
                } elseif ($ns === $text && $name === 's' && $inBlock > 0) {
                    $line .= str_repeat(' ', max(1, min(100, (int) $reader->getAttributeNs('c', $text))));
                } elseif ($ns === $text && $name === 'tab' && $inBlock > 0) {
                    $line .= "\t";
                } elseif ($ns === $text && $name === 'line-break' && $inBlock > 0) {
                    $line .= "\n";
                } elseif ($ns === $table && $name === 'table-row' && $cellDepth === 0) {
                    $row = [];
                } elseif ($ns === $table && $name === 'table-cell' && !$reader->isEmptyElement) {
                    if ($cellDepth++ === 0) $cellParts = [];
                }
            } elseif ($type === \XMLReader::END_ELEMENT) {
                if ($ns === $text && ($name === 'p' || $name === 'h') && $inBlock > 0) {
                    if (--$inBlock === 0) {
                        $value = $heading && $line !== '' ? "\n# " . $line : $line;
                        if ($cellDepth > 0) {
                            if (trim($value) !== '') $cellParts[] = trim($value);
                        } else {
                            $out[] = $value;
                        }
                    }
                } elseif ($ns === $table && $name === 'table-cell' && $cellDepth > 0) {
                    if (--$cellDepth === 0) $row[] = implode(' ', $cellParts);
                } elseif ($ns === $table && $name === 'table-row' && $cellDepth === 0) {
                    $out[] = implode(' | ', $row);
                }
            }
        }
        $reader->close();
        return self::done(implode("\n", $out));
    }

    private static function reader(string $xml): ?\XMLReader
    {
        if (!class_exists('XMLReader')) return null;
        $reader = new \XMLReader();
        // No LIBXML_NOENT, LIBXML_DTDLOAD, or LIBXML_PARSEHUGE: entities stay unexpanded and libxml's size guards stay on.
        return $reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT) ? $reader : null;
    }

    private static function zipEntry(string $path, string $entry): ?string
    {
        if (!class_exists('ZipArchive')) return null;
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return null;
        $stat = $zip->statName($entry);
        if (!is_array($stat) || (int) $stat['size'] > self::XML_LIMIT) {
            $zip->close();
            return null;
        }
        $data = $zip->getFromName($entry, self::XML_LIMIT);
        $zip->close();
        return is_string($data) ? $data : null;
    }

    private static function zipError(): string
    {
        return class_exists('ZipArchive')
            ? 'The file could not be opened, or its text part is larger than 8 MB. Save it again, split it, or paste the text instead.'
            : 'This server cannot open .docx or .odt files (PHP\'s zip extension is missing). Save the file as .txt, or paste the text instead.';
    }

    /* ── PDF ──────────────────────────────────────────────────────── */

    /** @return array{ok: bool, text: string, error: string} */
    private static function pdf(string $data): array
    {
        if (!str_starts_with(ltrim(substr($data, 0, 1024)), '%PDF')) return self::fail('That file is not a PDF.');
        try {
            $text = (new PdfText($data))->text();
        } catch (\Throwable $e) {
            return self::fail($e->getMessage() !== '' ? $e->getMessage() : 'The PDF could not be read.');
        }
        $text = self::clean($text);
        if (!self::looksLikeText($text)) {
            return self::fail('No readable text came out of this PDF. It may be a scan, or it may use a font encoding that cannot be decoded. Open it, copy the text, and paste it instead.');
        }
        return ['ok' => true, 'text' => $text, 'error' => ''];
    }

    /** Enough real words, and not mostly symbols, to be worth storing. */
    public static function looksLikeText(string $text): bool
    {
        if ($text === '') return false;
        $letters = preg_match_all('/\p{L}/u', $text);
        $visible = preg_match_all('/\S/u', $text);
        $words = preg_match_all('/\p{L}{2,}/u', $text);
        return $visible > 0 && $words >= 10 && $letters / $visible >= 0.6;
    }

    /** @return array{ok: bool, text: string, error: string} */
    private static function done(string $text): array
    {
        $text = self::clean($text);
        return $text === ''
            ? self::fail('No text was found in the file.')
            : ['ok' => true, 'text' => $text, 'error' => ''];
    }

    /** @return array{ok: bool, text: string, error: string} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'text' => '', 'error' => $message];
    }
}
