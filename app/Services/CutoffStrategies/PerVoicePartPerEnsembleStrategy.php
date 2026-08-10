<?php

declare(strict_types=1);

namespace App\Services\CutoffStrategies;

use App\Models\Ensemble;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\EnsembleCutoffService;
use Illuminate\Support\Collection;

/**
 * The base case: a single Ensemble on the Version (or, for a VoicePart
 * exclusive to one Ensemble of several, that Ensemble). Every ranked
 * candidate at or better than the cutoff is accepted into $ensemble.
 */
final class PerVoicePartPerEnsembleStrategy implements CutoffStrategyContract
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
