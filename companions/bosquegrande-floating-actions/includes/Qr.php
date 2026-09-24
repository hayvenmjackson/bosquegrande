<?php

declare(strict_types=1);

namespace BGCompanion\Actions;

if (!defined('ABSPATH')) exit;

/**
 * A compact QR code encoder (byte mode, versions 1 to 40, all four error
 * correction levels, automatic mask choice) that draws SVG. It follows the
 * structure of Project Nayuki's MIT-licensed QR Code generator. Everything
 * runs on the site; no outside service sees the address being encoded.
 */
final class Qr
{
    private const ECC_CODEWORDS_PER_BLOCK = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];
    private const NUM_ERROR_CORRECTION_BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];
    /** Error correction levels in order L, M, Q, H, and their format bits. */
    private const FORMAT_BITS = [1, 0, 3, 2];

    private int $size;
    private int $version;
    private int $ecl;
    /** @var list<list<bool>> */
    private array $modules = [];
    /** @var list<list<bool>> */
    private array $isFunction = [];

    /**
     * @param int $ecl Minimum error correction: 0 L, 1 M, 2 Q, 3 H. Raised when it costs no extra size.
     */
    public function __construct(string $data, int $ecl = 1)
    {
        $bytes = array_values(unpack('C*', $data) ?: []);
        $ecl = max(0, min(3, $ecl));
        $version = 0;
        for ($v = 1; $v <= 40; $v++) {
            if (self::dataBits(count($bytes), $v) <= self::numDataCodewords($v, $ecl) * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) throw new \InvalidArgumentException('Too much data for a QR code.');
        for ($e = $ecl + 1; $e <= 3; $e++) {
            if (self::dataBits(count($bytes), $version) <= self::numDataCodewords($version, $e) * 8) $ecl = $e;
        }
        $this->version = $version;
        $this->ecl = $ecl;
        $this->size = $version * 4 + 17;

        // Data bits: byte mode, length, data, terminator, padding.
        $bits = [];
        $append = static function (int $value, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; $i--) $bits[] = ($value >> $i) & 1;
        };
        $append(4, 4);
        $append(count($bytes), $version <= 9 ? 8 : 16);
        foreach ($bytes as $b) $append($b, 8);
        $capacity = self::numDataCodewords($version, $ecl) * 8;
        $append(0, min(4, $capacity - count($bits)));
        $append(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) $append($pad, 8);
        $codewords = array_fill(0, intdiv(count($bits), 8), 0);
        foreach ($bits as $i => $bit) $codewords[$i >> 3] |= $bit << (7 - ($i & 7));

        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addEccAndInterleave($codewords));

        $best = 0;
        $minPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();
            if ($penalty < $minPenalty) {
                $best = $mask;
                $minPenalty = $penalty;
            }
            $this->applyMask($mask);
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    public function size(): int
    {
        return $this->size;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function module(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < $this->size && $y < $this->size && $this->modules[$y][$x];
    }

    /** An SVG drawing with a quiet zone, dark modules in $dark on a $light background. */
    public function svg(int $border = 4, string $dark = '#000000', string $light = '#ffffff', string $title = ''): string
    {
        $dim = $this->size + $border * 2;
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges"'
            . ($title !== '' ? ' role="img" aria-label="' . htmlspecialchars($title, ENT_QUOTES) . '"' : ' aria-hidden="true"') . '>'
            . '<rect width="100%" height="100%" fill="' . htmlspecialchars($light, ENT_QUOTES) . '"/>'
            . '<path d="' . $path . '" fill="' . htmlspecialchars($dark, ENT_QUOTES) . '"/></svg>';
    }

    /* ── Layout ──────────────────────────────────────────────── */

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);
        $positions = $this->alignmentPositions();
        $count = count($positions);
        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $count - 1) || ($i === $count - 1 && $j === 0)) continue;
                $this->drawAlignment($positions[$i], $positions[$j]);
            }
        }
        $this->drawFormatBits(0);
        $this->drawVersion();
    }

    private function drawFinder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) continue;
                $dist = max(abs($dx), abs($dy));
                $this->setFunction($x, $y, $dist !== 2 && $dist !== 4);
            }
        }
    }

    private function drawAlignment(int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) $this->setFunction($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
        }
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) return [];
        $count = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $count * 3 + 5, $count * 4 - 4) * 2;
        $positions = [];
        for ($i = 0; $i < $count - 1; $i++) $positions[] = $this->size - 7 - $i * $step;
        $positions[] = 6;
        return array_reverse($positions);
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::FORMAT_BITS[$this->ecl] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) $this->setFunction(8, $i, $bit($i));
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $this->setFunction(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        $this->setFunction(8, $this->size - 8, true);
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) return;
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $dark);
            $this->setFunction($b, $a, $dark);
        }
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) continue;
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
                };
                if ($invert) $this->modules[$y][$x] = !$this->modules[$y][$x];
            }
        }
    }

    /** The standard penalty score; lower is easier to scan. */
    private function penalty(): int
    {
        $n = $this->size;
        $score = 0;
        $dark = 0;
        $finder = [[true, false, true, true, true, false, true, false, false, false, false], [false, false, false, false, true, false, true, true, true, false, true]];
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $line = [];
                for ($b = 0; $b < $n; $b++) $line[] = $pass === 0 ? $this->modules[$a][$b] : $this->modules[$b][$a];
                // Runs of five or more of the same color.
                $run = 1;
                for ($b = 1; $b <= $n; $b++) {
                    if ($b < $n && $line[$b] === $line[$b - 1]) {
                        $run++;
                    } else {
                        if ($run >= 5) $score += 3 + ($run - 5);
                        $run = 1;
                    }
                }
                // Patterns that look like finder patterns (with the quiet zone counting as light).
                $padded = array_merge(array_fill(0, 4, false), $line, array_fill(0, 4, false));
                for ($b = 0; $b + 11 <= count($padded); $b++) {
                    foreach ($finder as $pattern) {
                        $match = true;
                        for ($k = 0; $k < 11; $k++) {
                            if ($padded[$b + $k] !== $pattern[$k]) {
                                $match = false;
                                break;
                            }
                        }
                        if ($match) $score += 40;
                    }
                }
            }
        }
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($this->modules[$y][$x]) $dark++;
                if ($y + 1 < $n && $x + 1 < $n) {
                    $c = $this->modules[$y][$x];
                    if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) $score += 3;
                }
            }
        }
        $total = $n * $n;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        return $score + max(0, $k) * 10;
    }

    /* ── Error correction ────────────────────────────────────── */

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private function addEccAndInterleave(array $data): array
    {
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$this->ecl][$this->version];
        $eccLen = self::ECC_CODEWORDS_PER_BLOCK[$this->ecl][$this->version];
        $raw = intdiv(self::numRawDataModules($this->version), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $divisor = self::rsDivisor($eccLen);
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $len = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $block = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::rsRemainder($block, $divisor);
            if ($i < $numShort) $block[] = 0;
            $blocks[] = array_merge($block, $ecc);
        }
        $out = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) $out[] = $block[$i];
            }
        }
        return $out;
    }

    /** @return list<int> */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) $result[$j] ^= $result[$j + 1];
            }
            $root = self::gfMultiply($root, 0x02);
        }
        return $result;
    }

    /**
     * @param list<int> $data
     * @param list<int> $divisor
     * @return list<int>
     */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) $result[$i] ^= self::gfMultiply($coef, $factor);
        }
        return $result;
    }

    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    /* ── Capacity ────────────────────────────────────────────── */

    private static function dataBits(int $length, int $version): int
    {
        return 4 + ($version <= 9 ? 8 : 16) + $length * 8;
    }

    public static function numRawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $align = intdiv($version, 7) + 2;
            $result -= (25 * $align - 10) * $align - 55;
            if ($version >= 7) $result -= 36;
        }
        return $result;
    }

    public static function numDataCodewords(int $version, int $ecl): int
    {
        return intdiv(self::numRawDataModules($version), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$ecl][$version] * self::NUM_ERROR_CORRECTION_BLOCKS[$ecl][$version];
    }
}
