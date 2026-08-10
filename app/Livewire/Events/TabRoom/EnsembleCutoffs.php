<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom;

use App\Enums\CandidateStatus;
use App\Enums\CutoffStrategy;
use App\Models\Ensemble;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\EnsembleCutoffService;
use App\Services\EnsembleHistoryService;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tab Room Manager's Ensemble Cut-offs sub-module (Tab Room Module.docx):
 * per-Voice-Part ranked score lists, click-to-apply a cutoff. Delegates all
 * assignment logic to EnsembleCutoffService + the resolved CutoffStrategy —
 * this component is orchestration and display only.
 *
 * Two UI modes depending on Version::cutoff_strategy (see
 * CutoffStrategy::needsPerEnsembleCutoff()):
 * - Single shared cutoff (PerVoicePartPerEnsemble, AlternatingEnsembleAssignment):
 *   one click on a ranked score immediately resolves the whole Voice Part.
 * - Per-Ensemble cutoff (SequentialEnsembleFill, GradeSegmentedEnsembles):
 *   the ranked list is reference only; the manager enters a distinct score
 *   per eligible Ensemble and applies each in turn, then explicitly
 *   finalizes the Voice Part once every Ensemble has been entered.
 *
 * Once every candidate in a Voice Part has a decision, its column collapses
 * to a compact "Resolved" summary. "Reopen" ($reopenedVoiceParts) is purely
 * a display toggle — it never touches data. EnsembleCutoffService's
 * rankedCandidates() already includes already-decided candidates, so
 * clicking a different score re-decides the whole Voice Part in place
 * (previously-accepted candidates who no longer qualify move to
 * NotAccepted, and vice versa) without ever reverting anyone to Registered.
 */
#[Layout('components.layouts.app')]
class EnsembleCutoffs extends Component
{
    public Version $version;

    /**
     * Keyed "{voicePartId}_{ensembleId}" => score input string, for the
     * per-Ensemble cutoff UI mode.
     *
     * @var array<string, string>
     */
    public array $ensembleCutoffInputs = [];

    /**
     * Voice Part ids currently showing their full ranked list instead of
     * the collapsed "Resolved" summary — a display toggle only.
     *
     * @var list<int>
     */
    public array $reopenedVoiceParts = [];

    /**
     * Keyed "{ensembleId}_{seasonYear}_{voicePartId}" => count input string,
     * for the History panel's manual-entry grid. Hydrated once in mount()
     * from EnsembleHistory; saveHistoryRow() writes straight through, so
     * these stay authoritative without needing to be re-hydrated every
     * render.
     *
     * @var array<string, string>
     */
    public array $historyInputs = [];

    public function mount(Version $version, VersionRoleAssignmentService $roles, EnsembleHistoryService $history): void
    {
        abort_unless($roles->canManageTabRoom(Auth::user(), $version), 403);
        abort_if(
            $version->getRawOriginal('cutoff_strategy') === null,
            422,
            'Configure a cut-off strategy on this Version before using Ensemble Cut-offs.',
        );

        $this->version = $version;

        $ensembles = $version->ensembleOrder->map(fn ($order) => $order->ensemble);
        $seasonYears = $history->priorSeasonYears($version);
        $grid = $history->historyGrid($ensembles, $seasonYears);

        foreach ($grid as $ensembleId => $byYear) {
            foreach ($byYear as $seasonYear => $byVoicePart) {
                foreach ($byVoicePart as $voicePartId => $count) {
                    $this->historyInputs["{$ensembleId}_{$seasonYear}_{$voicePartId}"] = (string) $count;
                }
            }
        }
    }

    public function applyCutoff(int $voicePartId, int $score, EnsembleCutoffService $cutoffs): void
    {
        $voicePart = VoicePart::findOrFail($voicePartId);

        $cutoffs->applyCutoff($this->version, $voicePart, $score);

        Flux::toast(text: "Cutoff applied for {$voicePart->name}.", variant: 'success');
    }

    public function applyEnsembleCutoff(int $voicePartId, int $ensembleId, EnsembleCutoffService $cutoffs): void
    {
        $voicePart = VoicePart::findOrFail($voicePartId);
        $ensemble = Ensemble::findOrFail($ensembleId);

        $key = "{$voicePartId}_{$ensembleId}";
        $score = filter_var($this->ensembleCutoffInputs[$key] ?? '', FILTER_VALIDATE_INT);

        if ($score === false) {
            Flux::toast(text: 'Enter a numeric cutoff score first.', variant: 'danger');

            return;
        }

        $cutoffs->applyEnsembleCutoff($this->version, $voicePart, $ensemble, $score);

        Flux::toast(text: "Cutoff applied for {$ensemble->name} ({$voicePart->name}).", variant: 'success');
    }

    public function finalizeVoicePart(int $voicePartId, EnsembleCutoffService $cutoffs): void
    {
        $voicePart = VoicePart::findOrFail($voicePartId);

        $cutoffs->finalizeVoicePart($this->version, $voicePart);

        Flux::toast(text: "{$voicePart->name} finalized — remaining candidates marked not accepted.", variant: 'warning');
    }

    public function reopenVoicePart(int $voicePartId): void
    {
        if (! in_array($voicePartId, $this->reopenedVoiceParts, true)) {
            $this->reopenedVoiceParts[] = $voicePartId;
        }
    }

    public function collapseVoicePart(int $voicePartId): void
    {
        $this->reopenedVoiceParts = array_values(array_diff($this->reopenedVoiceParts, [$voicePartId]));
    }

    public function saveHistoryRow(int $ensembleId, int $seasonYear, EnsembleHistoryService $history): void
    {
        $ensemble = Ensemble::findOrFail($ensembleId);
        $voiceParts = $this->version->availableVoiceParts();

        $counts = $voiceParts->mapWithKeys(function (VoicePart $voicePart) use ($ensembleId, $seasonYear): array {
            $key = "{$ensembleId}_{$seasonYear}_{$voicePart->id}";

            return [$voicePart->id => $this->historyInputs[$key] ?? null];
        })->all();

        $history->saveHistoryRow($ensemble, $seasonYear, $counts);

        Flux::toast(text: "{$ensemble->name} — {$seasonYear} history saved.", variant: 'success');
    }

    public function render(EnsembleCutoffService $cutoffs, EnsembleHistoryService $history): View
    {
        $strategy = CutoffStrategy::from($this->version->getRawOriginal('cutoff_strategy'));
        $needsPerEnsemble = $strategy->needsPerEnsembleCutoff();

        $voiceParts = $this->version->availableVoiceParts();
        $ensembles = $this->version->ensembleOrder->map(fn ($order) => $order->ensemble);

        // A fixed palette cycled by ensembleOrder position — no Ensemble
        // color-editing UI exists yet (see Ensemble::$color), so this is
        // the one place the mapping is computed, shared by both the
        // summary table and the ranked score list below so an Ensemble's
        // color stays consistent across the whole page.
        $palette = ['#3b82f6', '#f97316', '#22c55e', '#a855f7', '#ef4444', '#14b8a6'];
        $ensembleColors = $ensembles->mapWithKeys(fn (Ensemble $ensemble, int $index): array => [
            $ensemble->id => $ensemble->color ?? $palette[$index % count($palette)],
        ]);

        $voicePartData = $voiceParts->map(function (VoicePart $voicePart) use ($cutoffs): array {
            $ranked = $cutoffs->rankedCandidates($this->version, $voicePart);

            $allDecided = $ranked->isNotEmpty() && $ranked->every(
                fn (array $row): bool => $row['candidate']->getRawOriginal('status') !== CandidateStatus::Registered->value,
            );

            return [
                'voicePart' => $voicePart,
                'ranked' => $ranked,
                'eligibleEnsembles' => $cutoffs->eligibleEnsembles($this->version, $voicePart),
                'allDecided' => $allDecided,
                'isReopened' => in_array($voicePart->id, $this->reopenedVoiceParts, true),
                'acceptedCount' => $ranked->filter(fn (array $row): bool => $row['candidate']->getRawOriginal('status') === CandidateStatus::Accepted->value)->count(),
                'notAcceptedCount' => $ranked->filter(fn (array $row): bool => $row['candidate']->getRawOriginal('status') === CandidateStatus::NotAccepted->value)->count(),
            ];
        });

        $acceptedCounts = $cutoffs->acceptedCounts($this->version);

        $countsByEnsembleVoicePart = [];
        foreach ($acceptedCounts as $row) {
            $countsByEnsembleVoicePart[$row['ensemble']->id][$row['voice_part_id']] = $row['count'];
        }

        // The same 8-slot categorical palette used for Ensembles above, but
        // assigned to Voice Parts instead — a different dimension, so its
        // own fixed order (Voice Part sort_order) rather than reusing
        // ensembleOrder. Validated on the "adjacent" pairlist (see
        // references/palette.md); slices carry a legend + hover readout
        // rather than relying on hue alone, per the accessibility pass.
        $voicePartColors = $voiceParts->values()->mapWithKeys(fn (VoicePart $voicePart, int $index): array => [
            $voicePart->id => $palette[$index % count($palette)],
        ]);

        $pieCharts = $ensembles->map(fn (Ensemble $ensemble): array => $this->buildPieChart(
            $ensemble,
            $voiceParts,
            $voicePartColors,
            $countsByEnsembleVoicePart[$ensemble->id] ?? [],
        ));

        $priorSeasonYears = $history->priorSeasonYears($this->version);

        return view('livewire.events.tab-room.ensemble-cutoffs', [
            'ensembleColors' => $ensembleColors,
            'needsPerEnsemble' => $needsPerEnsemble,
            'voiceParts' => $voiceParts,
            'voicePartData' => $voicePartData,
            'ensembles' => $ensembles,
            'countsByEnsembleVoicePart' => $countsByEnsembleVoicePart,
            'pieCharts' => $pieCharts,
            'priorSeasonYears' => $priorSeasonYears,
        ]);
    }

    /**
     * SVG pie-slice geometry for one Ensemble's accepted-candidate
     * breakdown by Voice Part — a 200×200 viewBox, center (100,100),
     * radius 80. Zero-count Voice Parts are skipped (no 0° slice). Each
     * slice carries a 2px surface-color stroke as the separating gap
     * between touching marks, not a border (see marks-and-anatomy.md) — the
     * actual stroke color is applied in the Blade view via a CSS variable
     * so it can swap for light/dark without recomputing geometry here.
     *
     * Slices are direct-labeled with the Voice Part's abbr, per
     * marks-and-anatomy.md's "label inside a colored fill" exception —
     * skipped on slices too small to hold text (< 8% share) rather than
     * clipped, per "measure first, don't clip." The exact count/percent for
     * every slice, labeled or not, stays reachable via hover/focus and the
     * Accepted Candidates table above, so nothing is gated behind the label.
     *
     * @param  Collection<int, VoicePart>  $voiceParts
     * @param  Collection<int, string>  $voicePartColors
     * @param  array<int, int>  $counts  voice_part_id => count
     * @return array{ensemble: Ensemble, total: int, slices: list<array{voicePart: VoicePart, count: int, percent: string, color: string, path: string, labelX: float, labelY: float, showLabel: bool, labelColor: string}>}
     */
    private function buildPieChart(Ensemble $ensemble, Collection $voiceParts, Collection $voicePartColors, array $counts): array
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
                    'labelColor' => $this->contrastTextColor($color),
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
     * White or near-black text, whichever clears contrast against $hex —
     * the "label inside a colored fill" exception in marks-and-anatomy.md,
     * computed rather than assumed so it stays correct if the palette
     * changes. Relative luminance per WCAG's sRGB coefficients.
     */
    private function contrastTextColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return $luminance > 0.55 ? '#0b0b0b' : '#ffffff';
    }
}
