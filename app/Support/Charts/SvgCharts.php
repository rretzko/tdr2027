<?php

declare(strict_types=1);

namespace App\Support\Charts;

/**
 * Server-drawn SVG charts for DomPDF, which can't run the Flux charts'
 * JavaScript. Each method returns a data: URI for an <img>, the form
 * DomPDF renders most reliably. Colors are the light-mode steps of the
 * Scorecard's categorical slots (a PDF prints on white).
 */
final class SvgCharts
{
    public const BLUE = '#2a78d6';

    public const ORANGE = '#eb6834';

    public const GREEN = '#1baf7a';

    private const INK = '#1a1a1a';

    private const MUTED = '#666666';

    private const GRID = '#e4e4e7';

    private const TRACK = '#f4f4f5';

    /**
     * Stacked columns, one per row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $series  field => color, bottom of the stack first
     * @param  string  $labelField  the row field printed under each column
     */
    public static function columns(array $rows, array $series, string $labelField, int $width = 700, int $height = 200): string
    {
        $left = 36;
        $bottom = 28;
        $top = 8;
        $plotWidth = $width - $left - 8;
        $plotHeight = $height - $bottom - $top;

        $max = 1;
        foreach ($rows as $row) {
            $max = max($max, array_sum(array_map(fn (string $field): int => (int) ($row[$field] ?? 0), array_keys($series))));
        }
        $step = self::niceStep($max, 4);
        $axisMax = (int) (ceil($max / $step) * $step);

        $svg = '';
        for ($value = 0; $value <= $axisMax; $value += $step) {
            $y = $top + $plotHeight - $value / $axisMax * $plotHeight;
            $svg .= sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="%s" stroke-width="1"/>', $left, $y, $width - 8, $y, self::GRID);
            $svg .= sprintf('<text x="%d" y="%.1f" font-size="9" fill="%s" text-anchor="end">%d</text>', $left - 4, $y + 3, self::MUTED, $value);
        }

        $count = max(1, count($rows));
        $slot = $plotWidth / $count;
        $barWidth = max(1.0, $slot - 2);
        $labelEvery = (int) max(1, ceil($count / 14));

        foreach ($rows as $index => $row) {
            $x = $left + $index * $slot + 1;
            $y = $top + $plotHeight;
            foreach ($series as $field => $color) {
                $value = (int) ($row[$field] ?? 0);
                if ($value <= 0) {
                    continue;
                }
                $h = $value / $axisMax * $plotHeight;
                $y -= $h;
                $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>', $x, $y, $barWidth, $h, $color);
            }
            if ($index % $labelEvery === 0) {
                $svg .= sprintf('<text x="%.1f" y="%d" font-size="9" fill="%s" text-anchor="middle">%s</text>', $x + $barWidth / 2, $height - 12, self::MUTED, self::e((string) ($row[$labelField] ?? '')));
            }
        }

        return self::uri($width, $height, $svg);
    }

    /**
     * One horizontal row per item: a blue bar over the accepted range, an
     * orange bar over the not-accepted range and a dark cut-off mark, on
     * one shared score axis.
     *
     * @param  list<array{label: string, line: int, acceptedRange: ?array{int, int}, missedRange: ?array{int, int}}>  $rows
     */
    public static function rangeBars(array $rows, int $width = 700): string
    {
        $labelWidth = 170;
        $rowHeight = 22;
        $top = 4;
        $axisHeight = 22;
        $height = $top + count($rows) * $rowHeight + $axisHeight;
        $plotLeft = $labelWidth + 6;
        $plotWidth = $width - $plotLeft - 12;

        $values = [];
        foreach ($rows as $row) {
            array_push($values, $row['line'], ...($row['acceptedRange'] ?? []), ...($row['missedRange'] ?? []));
        }
        $min = $values === [] ? 0 : min($values);
        $max = $values === [] ? 1 : max($values);
        $step = self::niceStep(max(1, $max - $min), 8);
        $axisMin = (int) (floor($min / $step) * $step);
        $axisMax = (int) (ceil($max / $step) * $step);
        $span = max(1, $axisMax - $axisMin);
        $x = fn (int $value): float => $plotLeft + ($value - $axisMin) / $span * $plotWidth;

        $svg = '';
        foreach ($rows as $index => $row) {
            $y = $top + $index * $rowHeight;
            $svg .= sprintf('<text x="%d" y="%.1f" font-size="10" fill="%s">%s</text>', 0, $y + 14, self::INK, self::e($row['label']));
            $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%d" fill="%s"/>', $plotLeft, $y + 2, $plotWidth, $rowHeight - 4, self::TRACK);
            if ($row['acceptedRange'] !== null) {
                $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="7" fill="%s"/>', $x($row['acceptedRange'][0]), $y + 4, max(2.0, $x($row['acceptedRange'][1]) - $x($row['acceptedRange'][0])), self::BLUE);
            }
            if ($row['missedRange'] !== null) {
                $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="7" fill="%s"/>', $x($row['missedRange'][0]), $y + 11, max(2.0, $x($row['missedRange'][1]) - $x($row['missedRange'][0])), self::ORANGE);
            }
            $svg .= sprintf('<rect x="%.1f" y="%.1f" width="2" height="%d" fill="%s"/>', $x($row['line']) - 1, $y + 1, $rowHeight - 2, self::INK);
        }

        $axisY = $top + count($rows) * $rowHeight + 2;
        $svg .= sprintf('<line x1="%.1f" y1="%d" x2="%.1f" y2="%d" stroke="#a1a1aa" stroke-width="1"/>', $plotLeft, $axisY, $plotLeft + $plotWidth, $axisY);
        for ($tick = $axisMin; $tick <= $axisMax; $tick += $step) {
            $svg .= sprintf('<text x="%.1f" y="%d" font-size="9" fill="%s" text-anchor="middle">%d</text>', $x($tick), $axisY + 13, self::MUTED, $tick);
        }

        return self::uri($width, $height, $svg);
    }

    /**
     * A pie with direct labels on slices of at least 8%.
     *
     * @param  list<array{label: string, count: int, color: string}>  $slices
     */
    public static function pie(array $slices, int $size = 150): string
    {
        $total = array_sum(array_column($slices, 'count'));
        $c = $size / 2;
        $r = $size / 2 - 4;
        $angle = -M_PI / 2;
        $svg = '';

        foreach ($slices as $slice) {
            if ($slice['count'] <= 0 || $total === 0) {
                continue;
            }
            $share = $slice['count'] / $total;
            $sweep = $share * 2 * M_PI;
            if ($slice['count'] === $total) {
                $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s" stroke="#ffffff" stroke-width="2"/>', $c, $c, $r, $slice['color']);
            } else {
                $svg .= sprintf(
                    '<path d="M %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f Z" fill="%s" stroke="#ffffff" stroke-width="2"/>',
                    $c, $c, $c + $r * cos($angle), $c + $r * sin($angle), $r, $r, $sweep > M_PI ? 1 : 0, $c + $r * cos($angle + $sweep), $c + $r * sin($angle + $sweep), $slice['color'],
                );
            }
            if ($share >= 0.08) {
                $mid = $angle + $sweep / 2;
                $svg .= sprintf('<text x="%.1f" y="%.1f" font-size="10" font-weight="bold" fill="#ffffff" text-anchor="middle">%s</text>', $c + $r * 0.6 * cos($mid), $c + $r * 0.6 * sin($mid) + 3, self::e($slice['label']));
            }
            $angle += $sweep;
        }

        return self::uri($size, $size, $svg);
    }

    /** A round step (1, 2, 2.5 or 5 × 10ⁿ) giving at most $ticks intervals over $span. */
    private static function niceStep(int $span, int $ticks): int
    {
        foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000] as $step) {
            if ($span / $step <= $ticks) {
                return $step;
            }
        }

        return 10000;
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function uri(int $width, int $height, string $body): string
    {
        $svg = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" font-family="DejaVu Sans, sans-serif">%s</svg>', $width, $height, $width, $height, $body);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
