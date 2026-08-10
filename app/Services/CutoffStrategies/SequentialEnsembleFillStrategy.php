<?php

declare(strict_types=1);

namespace App\Services\CutoffStrategies;

use App\Models\Ensemble;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\EnsembleCutoffService;
use Illuminate\Support\Collection;

/**
 * Cascading cutoffs: the manager applies each eligible Ensemble's cutoff
 * one at a time, in the Version's ensembleOrder priority, via
 * EnsembleCutoffService::applyEnsembleCutoff(). $rankedCandidates is always
 * "whoever is still unresolved" — a Candidate accepted by an earlier
 * Ensemble in the sequence is no longer status=Registered, so
 * EnsembleCutoffService::rankedCandidates() has already excluded them by
 * the time this Ensemble's turn comes; no explicit "remaining pool"
 * bookkeeping is needed here.
 */
final class SequentialEnsembleFillStrategy implements CutoffStrategyContract
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
        foreach ($rankedCandidates as $row) {
            if ($cutoffs->meetsCutoff($version, $row['total'], $cutoffScore)) {
                $cutoffs->acceptCandidate($row['candidate'], $ensemble, $row['total'], $row['scoreCount']);
            }
        }
    }
}
