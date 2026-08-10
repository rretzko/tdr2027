<?php

declare(strict_types=1);

namespace App\Services\CutoffStrategies;

use App\Models\Ensemble;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\EnsembleCutoffService;
use Illuminate\Support\Collection;

/**
 * One shared candidate pool, one cutoff per VoicePart: starting from the
 * best score, candidates at or better than the cutoff rotate between every
 * Ensemble eligible for this VoicePart (in ensembleOrder priority) — 1st
 * best *score* to Ensemble A, 2nd best score to Ensemble B, 3rd best score
 * to Ensemble A again, and so on. The rotation advances per distinct score
 * value, not per candidate — every candidate tied at the same score moves
 * together to the same Ensemble (three candidates tied at 100 all go to
 * Ensemble A; the next four, tied at 101, all go to Ensemble B), matching
 * the product owner's clarification that alternation assigns by score, not
 * by candidate. A VoicePart served by only one eligible Ensemble
 * degenerates to PerVoicePartPerEnsembleStrategy's behavior automatically
 * (rotation modulo 1 always selects the same, only, Ensemble).
 *
 * Deterministic by score-group order alone, independent of which
 * Ensemble's apply() call runs first or how many times —
 * EnsembleCutoffService::applyCutoff() calls every eligible Ensemble with
 * the same $cutoffScore in one transaction, so this only ever needs to
 * decide "does this score group's rotation slot belong to $ensemble," not
 * track state across calls.
 */
final class AlternatingEnsembleAssignmentStrategy implements CutoffStrategyContract
{
    public function apply(
        Version $version,
        VoicePart $voicePart,
        Ensemble $ensemble,
        int $cutoffScore,
        Collection $rankedCandidates,
        Collection $eligibleEnsembles,
        EnsembleCutoffService $cutoffs,
    ): void {
        $ensembleCount = max($eligibleEnsembles->count(), 1);
        $myIndex = $eligibleEnsembles->search(fn (Ensemble $candidate): bool => $candidate->id === $ensemble->id);

        if ($myIndex === false) {
            return;
        }

        $groupIndex = -1;
        $previousScore = null;

        foreach ($rankedCandidates as $row) {
            if (! $cutoffs->meetsCutoff($version, $row['total'], $cutoffScore)) {
                continue;
            }

            if ($previousScore === null || $row['total'] !== $previousScore) {
                $groupIndex++;
                $previousScore = $row['total'];
            }

            if ($groupIndex % $ensembleCount === $myIndex) {
                $cutoffs->acceptCandidate($row['candidate'], $ensemble, $row['total'], $row['scoreCount']);
            }
        }
    }
}
