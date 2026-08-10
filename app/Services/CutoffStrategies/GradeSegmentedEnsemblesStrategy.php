<?php

declare(strict_types=1);

namespace App\Services\CutoffStrategies;

use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\EnsembleCutoffService;
use Illuminate\Support\Collection;

/**
 * Candidates are partitioned by grade before any score comparison — a
 * 7th-grade soprano competes only for the junior Ensemble, an 11th-grade
 * soprano only for the senior Ensemble; they never compete against each
 * other. Disjoint, independent Ensembles resolved in parallel via separate
 * applyEnsembleCutoff() calls (each with its own cutoff score), not tiers of
 * one shared pool like AlternatingEnsembleAssignmentStrategy. Once filtered
 * to its own grade set, an Ensemble's pool is handled identically to
 * PerVoicePartPerEnsembleStrategy.
 */
final class GradeSegmentedEnsemblesStrategy implements CutoffStrategyContract
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
        $eligibleGrades = $ensemble->grades->pluck('grade')->map(fn ($grade): int => (int) $grade);

        foreach ($rankedCandidates as $row) {
            $candidate = $row['candidate'];

            if (! $this->gradeMatches($candidate, $eligibleGrades)) {
                continue;
            }

            if ($cutoffs->meetsCutoff($version, $row['total'], $cutoffScore)) {
                $cutoffs->acceptCandidate($candidate, $ensemble, $row['total'], $row['scoreCount']);
            }
        }
    }

    /**
     * @param  Collection<int, int>  $eligibleGrades
     */
    private function gradeMatches(Candidate $candidate, Collection $eligibleGrades): bool
    {
        if ($eligibleGrades->isEmpty()) {
            return true; // an Ensemble with no configured grades is unrestricted, same "empty = unrestricted" convention as Version::counties()
        }

        $grade = $candidate->student?->grade;

        return $grade !== null && $eligibleGrades->contains($grade);
    }
}
