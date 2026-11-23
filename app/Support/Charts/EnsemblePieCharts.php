<?php

declare(strict_types=1);

namespace App\Support\Charts;

use App\Models\Ensemble;
use App\Models\VoicePart;
use Illuminate\Support\Collection;

/**
 * One pie per Ensemble of its accepted candidates by Voice Part, drawn by
 * <x-ensemble-pie-charts>. Shared by Ensemble Cut-offs' "Ensemble
 * Breakdown" card and the Scorecard's Selection tab, so the two pages
 * draw the same pies in the same Voice Part colors.
 */
final class EnsemblePieCharts
{
    /**
     * The fixed categorical palette, assigned to Voice Parts in their
     * Version order (availableVoiceParts()). Ensemble Cut-offs also uses it
     * for Ensemble colors. Slices carry a label, hover readout and an
     * accessible name, so identity never rests on hue alone.
     */
    public const PALETTE = ['#3b82f6', '#f97316', '#22c55e', '#a855f7', '#ef4444', '#14b8a6'];

    /**
     * @param  Collection<int, Ensemble>  $ensembles  in the Version's Ensemble order
     * @param  Collection<int, VoicePart>  $voiceParts  the Version's Voice Parts, in order
     * @param  array<int, array<int, int>>  $counts  ensemble_id => voice_part_id => accepted count
     * @return Collection<int, array{ensemble: Ensemble, total: int, slices: list<array{voicePart: VoicePart, count: int, percent: string, color: string, path: string, labelX: float, labelY: float, showLabel: bool, labelColor: string}>}>
     */
    public static function build(Collection $ensembles, Collection $voiceParts, array $counts): Collection
    {
        $voicePartColors = $voiceParts->values()->mapWithKeys(fn (VoicePart $voicePart, int $index): array => [
            $voicePart->id => self::PALETTE[$index % count(self::PALETTE)],
        ]);

        return $ensembles->values()->map(fn (Ensemble $ensemble): array => self::pie($ensemble, $voiceParts, $voicePartColors, $counts[$ensemble->id] ?? []));
    }

    /**
     * SVG pie-slice geometry for one Ensemble — a 200×200 viewBox, center
     * (100,100), radius 80. Zero-count Voice Parts are skipped (no 0°
     * slice). Slices are direct-labeled with the Voice Part's abbr, skipped
     * on slices too small to hold text (< 8% share) rather than clipped;
     * the exact count/percent stays reachable via hover/focus.
     *
     * @param  Collection<int, VoicePart>  $voiceParts
     * @param  Collection<int, string>  $voicePartColors
     * @param  array<int, int>  $counts  voice_part_id => count
     * @return array{ensemble: Ensemble, total: int, slices: list<array{voicePart: VoicePart, count: int, percent: string, color: string, path: string, labelX: float, labelY: float, showLabel: bool, labelColor: string}>}
     */
    private static function pie(Ensemble $ensemble, Collection $voiceParts, Collection $voicePartColors, array $counts): array
    {
        $total = array_sum($counts);
        $cx = 100.0;
        $cy = 100.0;
        $r = 80.0;
        $labelRadius = $r * 0.62;
        $angle = -M_PI / 2; // start at 12 o'clock, sweep clockwise
        $slices = [];

        if ($total > 0) {
            foreach ($voiceParts as $voicePart) {
                $count = $counts[$voicePart->id] ?? 0;

                if ($count <= 0) {
                    continue;
                }

                $share = $count / $total;
                $sweep = $share * 2 * M_PI;
                $endAngle = $angle + $sweep;
                $midAngle = $angle + $sweep / 2;

                // A single 100%-share Voice Part has no second point to arc
                // to — draw a full circle instead of a degenerate M-L-A-Z path.
                if ($count === $total) {
                    $path = sprintf(
                        'M %.2f,%.2f m -%.2f,0 a %.2f,%.2f 0 1,1 %.2f,0 a %.2f,%.2f 0 1,1 -%.2f,0 Z',
                        $cx, $cy, $r, $r, $r, $r * 2, $r, $r, $r * 2,
                    );
                } else {
                    $x1 = $cx + $r * cos($angle);
                    $y1 = $cy + $r * sin($angle);
                    $x2 = $cx + $r * cos($endAngle);
                    $y2 = $cy + $r * sin($endAngle);
                    $largeArc = $sweep > M_PI ? 1 : 0;

                    $path = sprintf(
                        'M %.2f,%.2f L %.2f,%.2f A %.2f,%.2f 0 %d,1 %.2f,%.2f Z',
                        $cx, $cy, $x1, $y1, $r, $r, $largeArc, $x2, $y2,
                    );
                }

                $color = $voicePartColors[$voicePart->id];

                $slices[] = [
                    'voicePart' => $voicePart,
                    'count' => $count,
                    'percent' => number_format($share * 100, 1),
                    'color' => $color,
                    'path' => $path,
                    'labelX' => $cx + $labelRadius * cos($midAngle),
                    'labelY' => $cy + $labelRadius * sin($midAngle),
                    'showLabel' => $share >= 0.08,
                    'labelColor' => self::contrastTextColor($color),
                ];

                $angle = $endAngle;
            }
        }

        return [
            'ensemble' => $ensemble,
            'total' => $total,
            'slices' => $slices,
        ];
    }

    /**
     * White or near-black text, whichever clears contrast against $hex.
     * Relative luminance per WCAG's sRGB coefficients.
     */
    private static function contrastTextColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return $luminance > 0.55 ? '#0b0b0b' : '#ffffff';
    }
}
