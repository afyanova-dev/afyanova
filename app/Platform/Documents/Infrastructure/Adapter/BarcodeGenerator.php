<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Adapter;

use InvalidArgumentException;

final class BarcodeGenerator
{
    /**
     * Code 128 patterns (0-106).
     * Each string consists of 6 digits representing widths of alternating bars and spaces.
     * Stop symbol (106) has 7 digits.
     *
     * @var list<string>
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const STOP = 106;

    /**
     * Generate Code 128 (Subset B) barcode as an SVG string.
     */
    public function generateCode128Svg(string $text, int $height = 50, int $barWidth = 2, bool $showText = true): string
    {
        if ($text === '') {
            throw new InvalidArgumentException('Barcode text cannot be empty.');
        }

        $codes = [self::START_B];
        $checksum = self::START_B;

        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ascii = ord($text[$i]);
            if ($ascii < 32 || $ascii > 126) {
                throw new InvalidArgumentException("Character '{$text[$i]}' at position {$i} is not supported in Code 128B.");
            }
            $val = $ascii - 32;
            $codes[] = $val;
            $checksum += ($i + 1) * $val;
        }

        $codes[] = $checksum % 103;
        $codes[] = self::STOP;

        // Build binary modules string (1 = bar, 0 = space)
        $modules = '';
        foreach ($codes as $code) {
            $pattern = self::PATTERNS[$code];
            $isBar = true;
            for ($p = 0; $p < strlen($pattern); $p++) {
                $width = (int) $pattern[$p];
                $modules .= str_repeat($isBar ? '1' : '0', $width);
                $isBar = ! $isBar;
            }
        }

        // Quiet zones: 10 modules on each side
        $quietZone = 10;
        $totalModules = strlen($modules) + ($quietZone * 2);
        $totalWidth = $totalModules * $barWidth;
        $totalHeight = $showText ? ($height + 20) : $height;

        $rects = '';
        $x = $quietZone * $barWidth;
        $inBar = false;
        $barStart = 0;

        for ($m = 0; $m < strlen($modules); $m++) {
            if ($modules[$m] === '1') {
                if (! $inBar) {
                    $inBar = true;
                    $barStart = $x;
                }
            } else {
                if ($inBar) {
                    $w = $x - $barStart;
                    $rects .= "<rect x=\"{$barStart}\" y=\"0\" width=\"{$w}\" height=\"{$height}\" fill=\"#000000\"/>";
                    $inBar = false;
                }
            }
            $x += $barWidth;
        }

        if ($inBar) {
            $w = $x - $barStart;
            $rects .= "<rect x=\"{$barStart}\" y=\"0\" width=\"{$w}\" height=\"{$height}\" fill=\"#000000\"/>";
        }

        $textElement = '';
        if ($showText) {
            $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            $textY = $height + 14;
            $midX = (int) ($totalWidth / 2);
            $textElement = "<text x=\"{$midX}\" y=\"{$textY}\" text-anchor=\"middle\" font-family=\"monospace\" font-size=\"12\" fill=\"#000000\">{$escapedText}</text>";
        }

        return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$totalWidth} {$totalHeight}\" width=\"{$totalWidth}\" height=\"{$totalHeight}\" style=\"background:#ffffff;\">{$rects}{$textElement}</svg>";
    }

    /**
     * Generate 2D Matrix / QR-style verification barcode as SVG.
     * Uses a deterministic grid encoding with finder corners and payload bits.
     */
    public function generateQrCodeSvg(string $data, int $size = 180): string
    {
        if ($data === '') {
            throw new InvalidArgumentException('QR payload cannot be empty.');
        }

        $gridSize = 25; // 25x25 matrix
        $cellSize = (float) $size / $gridSize;

        // Initialize 2D grid: false = white, true = black
        $matrix = array_fill(0, $gridSize, array_fill(0, $gridSize, false));

        // Draw standard QR finder patterns in three corners (top-left, top-right, bottom-left)
        $this->drawFinderPattern($matrix, 0, 0);
        $this->drawFinderPattern($matrix, $gridSize - 7, 0);
        $this->drawFinderPattern($matrix, 0, $gridSize - 7);

        // Draw timing patterns
        for ($i = 8; $i < $gridSize - 8; $i++) {
            $matrix[6][$i] = ($i % 2 === 0);
            $matrix[$i][6] = ($i % 2 === 0);
        }

        // Hash data to distribute payload bits deterministically into the matrix
        $hash = hash('sha256', $data);
        $bits = '';
        for ($h = 0; $h < strlen($hash); $h++) {
            $bits .= sprintf('%04b', hexdec($hash[$h]));
        }

        $bitIdx = 0;
        $bitLen = strlen($bits);

        for ($r = 0; $r < $gridSize; $r++) {
            for ($c = 0; $c < $gridSize; $c++) {
                // Skip finder patterns and separators
                if ($this->isFinderZone($r, $c, $gridSize)) {
                    continue;
                }
                if ($r === 6 || $c === 6) {
                    continue; // Skip timing patterns
                }

                $matrix[$r][$c] = ($bits[$bitIdx % $bitLen] === '1');
                $bitIdx++;
            }
        }

        // Render to SVG rects
        $rects = '';
        for ($r = 0; $r < $gridSize; $r++) {
            for ($c = 0; $c < $gridSize; $c++) {
                if ($matrix[$r][$c]) {
                    $x = round($c * $cellSize, 2);
                    $y = round($r * $cellSize, 2);
                    $w = round($cellSize + 0.1, 2); // Slight overlap to prevent rendering seams
                    $rects .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$w}\" height=\"{$w}\" fill=\"#000000\"/>";
                }
            }
        }

        return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$size} {$size}\" width=\"{$size}\" height=\"{$size}\" style=\"background:#ffffff;padding:4px;\">{$rects}</svg>";
    }

    /**
     * @param  array<int, array<int, bool>>  $matrix
     */
    private function drawFinderPattern(array &$matrix, int $startRow, int $startCol): void
    {
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                $isOuter = ($r === 0 || $r === 6 || $c === 0 || $c === 6);
                $isInner = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $matrix[$startRow + $r][$startCol + $c] = ($isOuter || $isInner);
            }
        }
    }

    private function isFinderZone(int $row, int $col, int $gridSize): bool
    {
        // Top-left
        if ($row <= 7 && $col <= 7) {
            return true;
        }
        // Top-right
        if ($row <= 7 && $col >= ($gridSize - 8)) {
            return true;
        }
        // Bottom-left
        if ($row >= ($gridSize - 8) && $col <= 7) {
            return true;
        }

        return false;
    }
}
