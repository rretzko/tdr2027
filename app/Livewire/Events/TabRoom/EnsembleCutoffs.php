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
use App\Support\Charts\EnsemblePieCharts;
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
        $palette = EnsemblePieCharts::PALETTE;
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

        $pieCharts = EnsemblePieCharts::build($ensembles, $voiceParts, $countsByEnsembleVoicePart);

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
}
