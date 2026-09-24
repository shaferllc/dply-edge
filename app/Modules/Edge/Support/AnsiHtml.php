<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

/**
 * Turns ANSI SGR sequences in build / console logs into safe HTML spans
 * so the Build Journey UI can show colors instead of raw `[33m` codes
 * (the ESC byte is invisible in the browser, so operators only saw the
 * trailing CSI).
 */
final class AnsiHtml
{
    /** @var array<int, string> */
    private const FG = [
        30 => '#1f2937',
        31 => '#f87171',
        32 => '#4ade80',
        33 => '#facc15',
        34 => '#60a5fa',
        35 => '#e879f9',
        36 => '#22d3ee',
        37 => '#e5e7eb',
        90 => '#6b7280',
        91 => '#fca5a5',
        92 => '#86efac',
        93 => '#fde047',
        94 => '#93c5fd',
        95 => '#f0abfc',
        96 => '#67e8f9',
        97 => '#f9fafb',
    ];

    /** @var array<int, string> */
    private const BG = [
        40 => '#111827',
        41 => '#7f1d1d',
        42 => '#14532d',
        43 => '#713f12',
        44 => '#1e3a8a',
        45 => '#701a75',
        46 => '#164e63',
        47 => '#374151',
        100 => '#1f2937',
        101 => '#991b1b',
        102 => '#166534',
        103 => '#a16207',
        104 => '#1d4ed8',
        105 => '#a21caf',
        106 => '#0e7490',
        107 => '#9ca3af',
    ];

    public static function toHtml(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = EdgeLogCopy::forCustomer($text);

        // Normalize CR-only progress rewrites so the log stays readable.
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace("/\r[^\n]/", "\n", $text) ?? $text;

        $parts = preg_split('/(\x1B\[[0-9;]*m)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return e($text);
        }

        $html = '';
        $open = false;
        $styles = [];

        foreach ($parts as $part) {
            if (preg_match('/^\x1B\[([0-9;]*)m$/', $part, $m) === 1) {
                $codes = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                $styles = self::applySgr($styles, $codes);
                if ($open) {
                    $html .= '</span>';
                    $open = false;
                }
                $css = self::cssFromStyles($styles);
                if ($css !== '') {
                    $html .= '<span style="'.$css.'">';
                    $open = true;
                }

                continue;
            }

            $html .= e($part);
        }

        if ($open) {
            $html .= '</span>';
        }

        return $html;
    }

    /**
     * @param  array{fg?: string, bg?: string, bold?: bool, dim?: bool, underline?: bool}  $styles
     * @param  list<int>  $codes
     * @return array{fg?: string, bg?: string, bold?: bool, dim?: bool, underline?: bool}
     */
    private static function applySgr(array $styles, array $codes): array
    {
        $i = 0;
        $count = count($codes);
        while ($i < $count) {
            $code = $codes[$i];
            if ($code === 0) {
                $styles = [];
            } elseif ($code === 1) {
                $styles['bold'] = true;
            } elseif ($code === 2) {
                $styles['dim'] = true;
            } elseif ($code === 4) {
                $styles['underline'] = true;
            } elseif ($code === 22) {
                unset($styles['bold'], $styles['dim']);
            } elseif ($code === 24) {
                unset($styles['underline']);
            } elseif ($code === 39) {
                unset($styles['fg']);
            } elseif ($code === 49) {
                unset($styles['bg']);
            } elseif ($code === 38 && ($codes[$i + 1] ?? null) === 5 && isset($codes[$i + 2])) {
                $styles['fg'] = self::xterm256($codes[$i + 2]);
                $i += 2;
            } elseif ($code === 48 && ($codes[$i + 1] ?? null) === 5 && isset($codes[$i + 2])) {
                $styles['bg'] = self::xterm256($codes[$i + 2]);
                $i += 2;
            } elseif ($code === 38 && ($codes[$i + 1] ?? null) === 2 && isset($codes[$i + 4])) {
                $styles['fg'] = sprintf('#%02x%02x%02x', $codes[$i + 2], $codes[$i + 3], $codes[$i + 4]);
                $i += 4;
            } elseif ($code === 48 && ($codes[$i + 1] ?? null) === 2 && isset($codes[$i + 4])) {
                $styles['bg'] = sprintf('#%02x%02x%02x', $codes[$i + 2], $codes[$i + 3], $codes[$i + 4]);
                $i += 4;
            } elseif (isset(self::FG[$code])) {
                $styles['fg'] = self::FG[$code];
            } elseif (isset(self::BG[$code])) {
                $styles['bg'] = self::BG[$code];
            }
            $i++;
        }

        return $styles;
    }

    /**
     * @param  array{fg?: string, bg?: string, bold?: bool, dim?: bool, underline?: bool}  $styles
     */
    private static function cssFromStyles(array $styles): string
    {
        $css = [];
        if (isset($styles['fg'])) {
            $css[] = 'color:'.$styles['fg'];
        }
        if (isset($styles['bg'])) {
            $css[] = 'background-color:'.$styles['bg'];
        }
        if (! empty($styles['bold'])) {
            $css[] = 'font-weight:700';
        }
        if (! empty($styles['dim'])) {
            $css[] = 'opacity:0.7';
        }
        if (! empty($styles['underline'])) {
            $css[] = 'text-decoration:underline';
        }

        return implode(';', $css);
    }

    private static function xterm256(int $n): string
    {
        $n = max(0, min(255, $n));
        if ($n < 16) {
            $basic = [
                '#000000', '#800000', '#008000', '#808000', '#000080', '#800080', '#008080', '#c0c0c0',
                '#808080', '#ff0000', '#00ff00', '#ffff00', '#0000ff', '#ff00ff', '#00ffff', '#ffffff',
            ];

            return $basic[$n];
        }
        if ($n < 232) {
            $n -= 16;
            $r = intdiv($n, 36);
            $g = intdiv($n % 36, 6);
            $b = $n % 6;
            $levels = [0, 95, 135, 175, 215, 255];

            return sprintf('#%02x%02x%02x', $levels[$r], $levels[$g], $levels[$b]);
        }

        $gray = 8 + ($n - 232) * 10;

        return sprintf('#%02x%02x%02x', $gray, $gray, $gray);
    }
}
