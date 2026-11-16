<?php

declare(strict_types=1);

namespace App\Services\CutoffStrategies;

use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Version;
use App\Services\EnsembleCutoffService;
use Illuminate\Support\Collection;

/**
 * Candidates are partitioned by grade before any score comparison — a
 * 7th-grade soprano competes only for the junior Ensemble, an 11th-grade
 * soprano only for the senior Ensemble; they never compete against each
 * other. Each Ensemble has its own independent line over its own grade
 * partition, so a click always targets the (first, by ensembleOrder)
 * eligible Ensemble the clicked candidate's grade belongs to.
 */
final class GradeSegmentedEnsemblesStrategy implements PerEnsembleCutoffStrategy
{
    public function targetEnsemble(Candidate $clicked, Collection $eligibleEnsembles, Collection $appliedEnsembleIds): ?Ensemble
    {
        return $eligibleEnsembles->first(fn (Ensemble $ensemble): bool => $this->canJoin($clicked, $ensemble));
    }

    public function assign(Version $version, Collection $rankedCandidates, Collection $lines, EnsembleCutoffService $cutoffs): array
    {
        $assignments = [];

        foreach ($lines as $line) {
            foreach ($rankedCandidates as $row) {
                $candidate = $row['candidate'];

                if (isset($assignments[$candidate->id]) || ! $this->canJoin($candidate, $line['ensemble'])) {
                    continue;
                }

                if ($cutoffs->meetsCutoff($version, $row['total'], $line['score'])) {
                    $assignments[$candidate->id] = $line['ensemble']->id;
                }
            }
        }

        return $assignments;
    }

    public function canJoin(Candidate $candidate, Ensemble $ensemble): bool
    {
        $eligibleGrades = $ensemble->grades->pluck('grade')->map(fn ($grade): int => (int) $grade);

        if ($eligibleGrades->isEmpty()) {
            return true; // an Ensemble with no configured grades is unrestricted, same "empty = unrestricted" convention as Version::counties()
        }

        $grade = $candidate->student?->grade;

        return $grade !== null && $eligibleGrades->contains($grade);
    }
}
