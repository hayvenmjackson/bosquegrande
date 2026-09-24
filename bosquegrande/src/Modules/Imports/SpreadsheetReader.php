<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Imports;

if (!defined('ABSPATH')) exit;

/**
 * Sparse, streaming reader for CSV/TSV and ordinary OOXML workbooks.
 * It never constructs a worksheet-sized array: only one row and the
 * workbook's shared-string dictionary are resident at a time.
 */
final class SpreadsheetReader
{
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const MAX_UNCOMPRESSED = 268435456; // 256 MiB across the archive.
    private const MAX_RATIO = 100;
    private const MAX_ARCHIVE_ENTRIES = 5000;
    private const MAX_METADATA_BYTES = 4194304;
    private const MAX_SHEETS = 200;
    private const MAX_SHARED_STRINGS = 250000;
    private const MAX_SHARED_STRING_BYTES = 33554432;
    private const MAX_ROWS = 250000;
    private const MAX_COLUMNS = 512;
    private const MAX_CELLS = 5000000;
    private const MAX_CELL_BYTES = 65536;

    /** @return array{type:string,sheets:list<array{name:string,entry:string,rows:int,columns:int}>} */
    public function inventory(string $path, string $type): array
    {
        $type = strtolower($type);
        if (in_array($type, ['csv', 'tsv'], true)) {
            [$rows, $columns] = $this->csvDimensions($path, $type);
            return ['type' => $type, 'sheets' => [[
                'name' => 'Data', 'entry' => '', 'rows' => $rows, 'columns' => $columns,
            ]]];
        }
        if ($type !== 'xlsx') throw new \RuntimeException('Only .xlsx, .csv, and .tsv files are supported.');
        $this->assertXlsxSupport();
        $this->assertSafeArchive($path);
        $sheets = $this->workbookSheets($path);
        foreach ($sheets as &$sheet) {
            [$sheet['rows'], $sheet['columns']] = $this->xlsxDimensions($path, $sheet['entry']);
        }
        unset($sheet);
        if (!$sheets) throw new \RuntimeException('The workbook contains no readable worksheets.');
        return ['type' => 'xlsx', 'sheets' => $sheets];
    }

    /** @return array{row:int,cells:array<int,string>} One-based column indexes. */
    public function headers(string $path, string $type, string $sheet = ''): array
    {
        foreach ($this->rows($path, $type, $sheet, [], true) as $rowNo => $cells) {
            if ($cells) return ['row' => $rowNo, 'cells' => $cells];
        }
        return ['row' => 0, 'cells' => []];
    }

    /**
     * @param list<int> $wanted One-based column indexes; empty means all.
     * @return \Generator<int,array<int,string>> Row number => sparse cells.
     */
    public function rows(
        string $path,
        string $type,
        string $sheet = '',
        array $wanted = [],
        bool $includeHeader = false
    ): \Generator {
        $type = strtolower($type);
        if (in_array($type, ['csv', 'tsv'], true)) {
            yield from $this->csvRows($path, $type, $wanted, $includeHeader);
            return;
        }
        if ($type !== 'xlsx') throw new \RuntimeException('Unsupported spreadsheet type.');
        $this->assertXlsxSupport();
        $this->assertSafeArchive($path);
        $sheets = $this->workbookSheets($path);
        $selected = null;
        foreach ($sheets as $candidate) {
            if ($sheet === '' || hash_equals($candidate['name'], $sheet)) {
                $selected = $candidate;
                break;
            }
        }
        if (!$selected) throw new \RuntimeException('The selected worksheet was not found.');
        $strings = $this->sharedStrings($path);
        $headerSeen = false;
        foreach ($this->xlsxRows($path, $selected['entry'], $strings, $wanted) as $rowNo => $cells) {
            if (!$headerSeen && $cells) {
                $headerSeen = true;
                if ($includeHeader) yield $rowNo => $cells;
                continue;
            }
            if ($headerSeen && $cells) yield $rowNo => $cells;
        }
    }

    private function assertXlsxSupport(): void
    {
        if (!class_exists('ZipArchive') || !class_exists('XMLReader')) {
            throw new \RuntimeException('XLSX import requires the standard PHP ZipArchive and XMLReader extensions. CSV remains available.');
        }
    }

    private function assertSafeArchive(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('The workbook ZIP container could not be opened.');
        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            $zip->close();
            throw new \RuntimeException('The workbook contains too many archive entries.');
        }
        $compressed = max(1, (int) filesize($path));
        $expanded = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) continue;
            $name = (string) ($stat['name'] ?? '');
            if (str_contains($name, '../') || str_starts_with($name, '/')) {
                $zip->close();
                throw new \RuntimeException('The workbook contains an unsafe archive path.');
            }
            $size = max(0, (int) ($stat['size'] ?? 0));
            if (in_array($name, ['xl/workbook.xml', 'xl/_rels/workbook.xml.rels'], true)
                && $size > self::MAX_METADATA_BYTES
            ) {
                $zip->close();
                throw new \RuntimeException('The workbook metadata exceeds BosqueGrande\'s safety limit.');
            }
            $expanded += $size;
            if ($expanded > self::MAX_UNCOMPRESSED || $expanded > $compressed * self::MAX_RATIO) {
                $zip->close();
                throw new \RuntimeException('The workbook expands beyond BosqueGrande\'s safety limit.');
            }
        }
        $required = ['xl/workbook.xml', 'xl/_rels/workbook.xml.rels'];
        foreach ($required as $entry) {
            if ($zip->locateName($entry) === false) {
                $zip->close();
                throw new \RuntimeException('This file is not a complete XLSX workbook.');
            }
        }
        $zip->close();
    }

    /** @return list<array{name:string,entry:string,rows:int,columns:int}> */
    private function workbookSheets(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('Workbook could not be opened.');
        $relsXml = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        $bookXml = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();
        if ($relsXml === '' || $bookXml === '') throw new \RuntimeException('Workbook metadata is missing.');

        $rels = [];
        $reader = new \XMLReader();
        if (!$reader->XML($relsXml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('Workbook relationships are unreadable.');
        }
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'Relationship') continue;
            $id = (string) $reader->getAttribute('Id');
            $target = (string) $reader->getAttribute('Target');
            if ($id !== '' && $target !== '') {
                $target = ltrim(str_replace('\\', '/', $target), '/');
                if (str_starts_with($target, 'xl/')) $entry = $target;
                else $entry = 'xl/' . preg_replace('#^(\.\./)+#', '', $target);
                $rels[$id] = $entry;
                if (count($rels) > self::MAX_ARCHIVE_ENTRIES) {
                    $reader->close();
                    throw new \RuntimeException('The workbook contains too many relationships.');
                }
            }
        }
        $reader->close();

        $out = [];
        if (!$reader->XML($bookXml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('Workbook sheet metadata is unreadable.');
        }
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'sheet') continue;
            $name = trim((string) $reader->getAttribute('name'));
            $rid = (string) ($reader->getAttributeNs('id', self::REL_NS) ?: $reader->getAttribute('r:id'));
            $entry = (string) ($rels[$rid] ?? '');
            if ($name !== '' && $entry !== '') {
                $out[] = ['name' => $name, 'entry' => $entry, 'rows' => 0, 'columns' => 0];
                if (count($out) > self::MAX_SHEETS) {
                    $reader->close();
                    throw new \RuntimeException('The workbook contains too many worksheets.');
                }
            }
        }
        $reader->close();
        return $out;
    }

    /** @return array{int,int} */
    private function xlsxDimensions(string $path, string $entry): array
    {
        $reader = $this->openZipXml($path, $entry);
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'dimension') {
                $ref = (string) $reader->getAttribute('ref');
                $reader->close();
                if (preg_match('/(?:[A-Z]+\d+:)?([A-Z]+)(\d+)$/i', $ref, $m)) {
                    $rows = (int) $m[2];
                    $columns = self::columnIndex($m[1]);
                    if ($rows > self::MAX_ROWS || $columns > self::MAX_COLUMNS) {
                        throw new \RuntimeException('The worksheet dimensions exceed BosqueGrande\'s import limits.');
                    }
                    return [$rows, $columns];
                }
                break;
            }
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'sheetData') break;
        }
        $reader->close();
        return [0, 0];
    }

    /** @return list<string> */
    private function sharedStrings(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return [];
        $exists = $zip->locateName('xl/sharedStrings.xml') !== false;
        $zip->close();
        if (!$exists) return [];
        $reader = $this->openZipXml($path, 'xl/sharedStrings.xml');
        $strings = [];
        $bytes = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'si') continue;
            $depth = $reader->depth;
            $text = '';
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 't') {
                    $text .= $reader->readString();
                    if (strlen($text) > self::MAX_CELL_BYTES) {
                        $reader->close();
                        throw new \RuntimeException('A shared workbook value exceeds BosqueGrande\'s import limit.');
                    }
                }
                if ($reader->nodeType === \XMLReader::END_ELEMENT
                    && $reader->localName === 'si' && $reader->depth === $depth
                ) break;
            }
            $strings[] = $text;
            $bytes += strlen($text);
            if (count($strings) > self::MAX_SHARED_STRINGS || $bytes > self::MAX_SHARED_STRING_BYTES) {
                $reader->close();
                throw new \RuntimeException('The workbook shared-string table exceeds BosqueGrande\'s import limits.');
            }
        }
        $reader->close();
        return $strings;
    }

    /**
     * @param list<string> $strings @param list<int> $wanted
     * @return \Generator<int,array<int,string>>
     */
    private function xlsxRows(string $path, string $entry, array $strings, array $wanted): \Generator
    {
        $want = $wanted ? array_fill_keys(array_map('intval', $wanted), true) : [];
        $reader = $this->openZipXml($path, $entry);
        $rowsSeen = 0;
        $cellsSeen = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row') continue;
            $rowsSeen++;
            if ($rowsSeen > self::MAX_ROWS) {
                $reader->close();
                throw new \RuntimeException('The worksheet contains too many rows.');
            }
            $rowDepth = $reader->depth;
            $rowNo = (int) $reader->getAttribute('r');
            $cells = [];
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'c') {
                    $cellsSeen++;
                    if ($cellsSeen > self::MAX_CELLS) {
                        $reader->close();
                        throw new \RuntimeException('The worksheet contains too many cells.');
                    }
                    $ref = (string) $reader->getAttribute('r');
                    $column = self::columnIndex($ref);
                    if ($column < 1 || $column > self::MAX_COLUMNS) {
                        $reader->close();
                        throw new \RuntimeException('A worksheet column exceeds BosqueGrande\'s import limits.');
                    }
                    $type = (string) $reader->getAttribute('t');
                    $cellDepth = $reader->depth;
                    $raw = '';
                    // XMLReader emits no matching END_ELEMENT event for a
                    // self-closing <c/>. Entering the child loop anyway would
                    // consume the next populated cell and, for a trailing
                    // blank, sometimes the next worksheet row.
                    if (!$reader->isEmptyElement) {
                        while ($reader->read()) {
                            if ($reader->nodeType === \XMLReader::ELEMENT && in_array($reader->localName, ['v', 't'], true)) {
                                $raw .= $reader->readString();
                                if (strlen($raw) > self::MAX_CELL_BYTES) {
                                    $reader->close();
                                    throw new \RuntimeException('A worksheet cell exceeds BosqueGrande\'s import limit.');
                                }
                            }
                            if ($reader->nodeType === \XMLReader::END_ELEMENT
                                && $reader->localName === 'c' && $reader->depth === $cellDepth
                            ) break;
                        }
                    }
                    if ($raw !== '' && (!$want || isset($want[$column]))) {
                        if ($type === 's' && ctype_digit($raw)) $raw = (string) ($strings[(int) $raw] ?? '');
                        elseif ($type === 'b') $raw = $raw === '1' ? 'TRUE' : 'FALSE';
                        $cells[$column] = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
                if ($reader->nodeType === \XMLReader::END_ELEMENT
                    && $reader->localName === 'row' && $reader->depth === $rowDepth
                ) break;
            }
            if ($rowNo < 1) $rowNo++;
            yield $rowNo => $cells;
        }
        $reader->close();
    }

    private function openZipXml(string $path, string $entry): \XMLReader
    {
        $reader = new \XMLReader();
        $uri = 'zip://' . str_replace('#', '%23', $path) . '#' . ltrim($entry, '/');
        if (!$reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('A workbook component could not be streamed.');
        }
        return $reader;
    }

    private static function columnIndex(string $reference): int
    {
        if (strlen($reference) > 32) return self::MAX_COLUMNS + 1;
        if (!preg_match('/^([A-Z]+)/i', $reference, $m)) return 0;
        $total = 0;
        foreach (str_split(strtoupper($m[1])) as $char) $total = $total * 26 + ord($char) - 64;
        return $total;
    }

    /** @return array{int,int} */
    private function csvDimensions(string $path, string $type): array
    {
        $delimiter = $this->delimiter($path, $type);
        $handle = fopen($path, 'rb');
        if (!$handle) throw new \RuntimeException('The delimited file could not be opened.');
        $rows = 0;
        $columns = 0;
        $cells = 0;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($this->blankCsvRow($row)) continue;
            $rows++;
            $columns = max($columns, count($row));
            $cells += count($row);
            if ($rows > self::MAX_ROWS || $columns > self::MAX_COLUMNS || $cells > self::MAX_CELLS) {
                fclose($handle);
                throw new \RuntimeException('The delimited file exceeds BosqueGrande\'s row, column, or cell limits.');
            }
            foreach ($row as $value) {
                if (strlen((string) $value) > self::MAX_CELL_BYTES) {
                    fclose($handle);
                    throw new \RuntimeException('A delimited-file cell exceeds BosqueGrande\'s import limit.');
                }
            }
        }
        fclose($handle);
        return [$rows, $columns];
    }

    /** @param list<int> $wanted @return \Generator<int,array<int,string>> */
    private function csvRows(string $path, string $type, array $wanted, bool $includeHeader): \Generator
    {
        $delimiter = $this->delimiter($path, $type);
        $handle = fopen($path, 'rb');
        if (!$handle) throw new \RuntimeException('The delimited file could not be opened.');
        $want = $wanted ? array_fill_keys(array_map('intval', $wanted), true) : [];
        $headerSeen = false;
        $line = 0;
        $cellsSeen = 0;
        try {
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if ($this->blankCsvRow($row)) continue;
                $cellsSeen += count($row);
                if ($line > self::MAX_ROWS || count($row) > self::MAX_COLUMNS || $cellsSeen > self::MAX_CELLS) {
                    throw new \RuntimeException('The delimited file exceeds BosqueGrande\'s row, column, or cell limits.');
                }
                $cells = [];
                foreach ($row as $zero => $value) {
                    if (strlen((string) $value) > self::MAX_CELL_BYTES) {
                        throw new \RuntimeException('A delimited-file cell exceeds BosqueGrande\'s import limit.');
                    }
                    $column = $zero + 1;
                    if (!$want || isset($want[$column])) $cells[$column] = (string) $value;
                }
                if (!$headerSeen) {
                    $headerSeen = true;
                    if ($includeHeader) yield $line => $cells;
                    continue;
                }
                yield $line => $cells;
            }
        } finally {
            fclose($handle);
        }
    }

    private function delimiter(string $path, string $type): string
    {
        if ($type === 'tsv') return "\t";
        $handle = fopen($path, 'rb');
        $line = $handle ? (string) fgets($handle) : '';
        if ($handle) fclose($handle);
        $counts = [',' => substr_count($line, ','), "\t" => substr_count($line, "\t"), ';' => substr_count($line, ';')];
        arsort($counts);
        $picked = (string) array_key_first($counts);
        return ($counts[$picked] ?? 0) > 0 ? $picked : ',';
    }

    /** @param array<int,mixed> $row */
    private function blankCsvRow(array $row): bool
    {
        foreach ($row as $value) if (trim((string) $value) !== '') return false;
        return true;
    }
}
