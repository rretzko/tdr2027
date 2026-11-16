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
 *   each click on a ranked score immediately draws (or moves) one
 *   Ensemble's line — the strategy decides which Ensemble from the clicked
 *   row (EnsembleCutoffService::applyScoreClick()) — so a cascading Voice
 *   Part takes one click per Ensemble. Candidates below every line stay
 *   Registered until the single event-wide Finalize (prepareFinalize() →
 *   confirmFinalize()), which refuses to run while anything would be
 *   orphaned (EnsembleCutoffService::finalizeSummary()).
 *
 * Once every candidate in a Voice Part has a decision, its column collapses
 * to a compact "Resolved" summary. "Reopen" ($reopenedVoiceParts) is purely
 * a display toggle — it never touches data. EnsembleCutoffService's
 * rankedCandidates() already includes already-decided candidates, so
 * clicking a different score re-decides the whole Voice Part in place
 * (previously-accepted candidates who no longer qualify move to
 * NotAccepted, and vice versa) without ever reverting anyone to Registered.
 *
 * A Closed Version stays viewable, but applyCutoff(), clickScore(),
 * resetVoicePart() and finalizing are blocked until the audition is reopened via
 * CloseAudition (same rule as AddEditScores). History entry is about prior
 * seasons, so it stays editable.
 */
#[Layout('components.layouts.app')]
class EnsembleCutoffs extends Component
{
    public Version $version;

    /**
     * Problems that stopped the last Finalize attempt, shown under the
     * Finalize button until the next attempt.
     *
     * @var list<string>
     */
    public array $finalizeProblems = [];

    /**
     * What a confirmed Finalize will do, for the confirmation modal.
     *
     * @var array{rejectCount: int, noShowCount: int}|null
     */
    public ?array $finalizePreview = null;

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
        abort_if($this->version->isClosed(), 422);

        $voicePart = VoicePart::findOrFail($voicePartId);

        $cutoffs->applyCutoff($this->version, $voicePart, $score);

        Flux::toast(text: "Cutoff applied for {$voicePart->name}.", variant: 'success');
    }

    /**
     * Per-Ensemble mode only: a click on a ranked row applies immediately —
     * the service picks the Ensemble and uses the row's own total as the
     * line, so no score is trusted from the browser.
     */
    public function clickScore(int $voicePartId, int $candidateId, EnsembleCutoffService $cutoffs): void
    {
        abort_if($this->version->isClosed(), 422);

        $voicePart = VoicePart::findOrFail($voicePartId);
        $result = $cutoffs->applyScoreClick($this->version, $voicePart, $candidateId);

        if ($result === null) {
            Flux::toast(text: "No Ensemble for {$voicePart->abbr} can take that candidate — check the Ensembles' voice parts and grades.", variant: 'danger');

            return;
        }

        $this->finalizeProblems = [];

        Flux::toast(text: "{$voicePart->abbr}: {$result['ensemble']->name} cutoff set to {$result['score']}.", variant: 'success');
    }

    public function resetVoicePart(int $voicePartId, EnsembleCutoffService $cutoffs): void
    {
        abort_if($this->version->isClosed(), 422);

        $voicePart = VoicePart::findOrFail($voicePartId);
        $cutoffs->resetVoicePart($this->version, $voicePart);

        Flux::toast(text: "{$voicePart->abbr} cutoffs cleared.", variant: 'warning');
    }

    /**
     * Step 1 of the event-wide Finalize: run every guard first. Problems
     * are listed under the button; otherwise the confirmation modal opens
     * with exactly what will happen.
     */
    public function prepareFinalize(EnsembleCutoffService $cutoffs): void
    {
        abort_if($this->version->isClosed(), 422);

        $summary = $cutoffs->finalizeSummary($this->version);
        $this->finalizeProblems = $summary['problems'];
        $this->finalizePreview = null;

        if ($this->finalizeProblems !== []) {
            return;
        }

        $this->finalizePreview = ['rejectCount' => $summary['rejectCount'], 'noShowCount' => $summary['noShowCount']];
        Flux::modal('finalize-cutoffs')->show();
    }

    /**
     * Step 2: finalizeAll() re-runs the guards itself, so a score edited
     * between the two steps still can't orphan anyone.
     */
    public function confirmFinalize(EnsembleCutoffService $cutoffs): void
    {
        abort_if($this->version->isClosed(), 422);

        $this->finalizeProblems = $cutoffs->finalizeAll($this->version);
        $this->finalizePreview = null;
        Flux::modal('finalize-cutoffs')->close();

        if ($this->finalizeProblems !== []) {
            return;
        }

        $this->reopenedVoiceParts = [];

        Flux::toast(text: 'Cut-offs finalized — every remaining candidate has a decision.', variant: 'success');
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

        $perEnsembleStrategy = $needsPerEnsemble ? $cutoffs->resolvePerEnsembleStrategy($this->version) : null;
        $splitByGrade = $strategy === CutoffStrategy::GradeSegmentedEnsembles;

        // One column per Voice Part — except, under GradeSegmentedEnsembles,
        // a Voice Part shared by several Ensembles gets one sub-column per
        // Ensemble (its grade partition), so each line is drawn over only
        // the candidates it can apply to.
        $voicePartData = $voiceParts->flatMap(function (VoicePart $voicePart) use ($cutoffs, $needsPerEnsemble, $perEnsembleStrategy, $splitByGrade): array {
            $ranked = $cutoffs->rankedCandidates($this->version, $voicePart);
            $eligibleEnsembles = $cutoffs->eligibleEnsembles($this->version, $voicePart);
            $lines = $needsPerEnsemble ? $cutoffs->linesFor($this->version, $voicePart, $eligibleEnsembles) : collect();

            $column = fn (Collection $rows, ?Ensemble $ensemble, bool $clickable): array => [
                'key' => $voicePart->id.'-'.($ensemble->id ?? ($clickable ? 'all' : 'none')),
                'voicePart' => $voicePart,
                'subLabel' => $ensemble !== null ? $ensemble->name : ($clickable ? null : 'No eligible Ensemble'),
                'ranked' => $rows->values(),
                'clickable' => $clickable,
                'lines' => $ensemble === null ? $lines : $lines->filter(fn (array $line): bool => $line['ensemble']->id === $ensemble->id)->values(),
                'hasLines' => $lines->isNotEmpty(),
                'allDecided' => $rows->isNotEmpty() && $rows->every(
                    fn (array $row): bool => $row['candidate']->getRawOriginal('status') !== CandidateStatus::Registered->value,
                ),
                'isReopened' => in_array($voicePart->id, $this->reopenedVoiceParts, true),
                'acceptedCount' => $rows->filter(fn (array $row): bool => $row['candidate']->getRawOriginal('status') === CandidateStatus::Accepted->value)->count(),
                'notAcceptedCount' => $rows->filter(fn (array $row): bool => $row['candidate']->getRawOriginal('status') === CandidateStatus::NotAccepted->value)->count(),
            ];

            if (! $splitByGrade || $perEnsembleStrategy === null || $eligibleEnsembles->count() < 2) {
                return [$column($ranked, null, true)];
            }

            $columns = [];
            $placed = [];

            foreach ($eligibleEnsembles as $ensemble) {
                $rows = $ranked->filter(function (array $row) use ($perEnsembleStrategy, $ensemble, &$placed): bool {
                    if (isset($placed[$row['candidate']->id]) || ! $perEnsembleStrategy->canJoin($row['candidate'], $ensemble)) {
                        return false;
                    }

                    return $placed[$row['candidate']->id] = true;
                });
                $columns[] = $column($rows, $ensemble, true);
            }

            $unplaced = $ranked->reject(fn (array $row): bool => isset($placed[$row['candidate']->id]));
            if ($unplaced->isNotEmpty()) {
                $columns[] = $column($unplaced, null, false);
            }

            return $columns;
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
            'versionIsClosed' => $this->version->isClosed(),
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
