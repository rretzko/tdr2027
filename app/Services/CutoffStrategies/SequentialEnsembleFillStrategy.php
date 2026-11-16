<?php

declare(strict_types=1);

namespace App\Services\CutoffStrategies;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Version;
use App\Services\EnsembleCutoffService;
use Illuminate\Support\Collection;

/**
 * Cascading cutoffs: each eligible Ensemble, in the Version's ensembleOrder
 * priority, takes every not-yet-claimed candidate at or better than its own
 * line. The manager draws the lines one click at a time, top Ensemble
 * first:
 *  - clicking a row already accepted into an Ensemble that holds a line
 *    moves THAT Ensemble's line to the clicked score;
 *  - clicking any other row (below every line) draws the next Ensemble's
 *    line, or — once every Ensemble has one — moves the last Ensemble's
 *    line down to it.
 */
final class SequentialEnsembleFillStrategy implements PerEnsembleCutoffStrategy
{
    public function targetEnsemble(Candidate $clicked, Collection $eligibleEnsembles, Collection $appliedEnsembleIds): ?Ensemble
    {
        if ($eligibleEnsembles->isEmpty()) {
            return null;
        }

        $acceptedInto = $clicked->getRawOriginal('status') === CandidateStatus::Accepted->value
            ? $clicked->accepted_ensemble_id
            : null;

        if ($acceptedInto !== null && $appliedEnsembleIds->contains($acceptedInto)) {
            return $eligibleEnsembles->firstWhere('id', $acceptedInto);
        }

        return $eligibleEnsembles->first(fn (Ensemble $ensemble): bool => ! $appliedEnsembleIds->contains($ensemble->id))
            ?? $eligibleEnsembles->last();
    }

    public function assign(Version $version, Collection $rankedCandidates, Collection $lines, EnsembleCutoffService $cutoffs): array
    {
        $assignments = [];

        foreach ($lines as $line) {
            foreach ($rankedCandidates as $row) {
                $candidateId = $row['candidate']->id;

                if (! isset($assignments[$candidateId]) && $cutoffs->meetsCutoff($version, $row['total'], $line['score'])) {
                    $assignments[$candidateId] = $line['ensemble']->id;
                }
            }
        }

        return $assignments;
    }

    public function canJoin(Candidate $candidate, Ensemble $ensemble): bool
    {
        return true;
    }
}
