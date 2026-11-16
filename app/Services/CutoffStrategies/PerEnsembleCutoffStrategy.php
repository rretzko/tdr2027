<?php

declare(strict_types=1);

namespace App\Services\CutoffStrategies;

use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Version;
use App\Services\EnsembleCutoffService;
use Illuminate\Support\Collection;

/**
 * The cut-off strategies that draw a separate line per Ensemble within a
 * Voice Part (CutoffStrategy::needsPerEnsembleCutoff()): SequentialEnsembleFill
 * and GradeSegmentedEnsembles. Unlike CutoffStrategyContract, these never
 * write anything themselves — EnsembleCutoffService::applyScoreClick()
 * stores each line as a VersionEnsembleCutoff row, asks assign() for the
 * full Voice Part assignment those lines imply, and diffs it against the
 * candidates' current decisions. Recomputing from every stored line on
 * every click is what lets a later click move an earlier Ensemble's line
 * and have the freed (or newly claimed) candidates flow correctly through
 * the Ensembles after it.
 */
interface PerEnsembleCutoffStrategy
{
    /**
     * Which Ensemble a click on $clicked's ranked row sets the line for —
     * null when no eligible Ensemble could ever take $clicked.
     *
     * @param  Collection<int, Ensemble>  $eligibleEnsembles  in the Version's ensembleOrder priority
     * @param  Collection<int, int>  $appliedEnsembleIds  Ensembles that already hold a line in this Voice Part
     */
    public function targetEnsemble(Candidate $clicked, Collection $eligibleEnsembles, Collection $appliedEnsembleIds): ?Ensemble;

    /**
     * The complete assignment the applied lines imply for one Voice Part.
     *
     * @param  Collection<int, array{candidate: Candidate, total: int, scoreCount: int}>  $rankedCandidates  completed candidates, best-to-worst, in any decision state
     * @param  Collection<int, array{ensemble: Ensemble, score: int}>  $lines  applied lines, in ensembleOrder priority
     * @return array<int, int> candidate id => ensemble id; candidates absent from the map are unassigned
     */
    public function assign(Version $version, Collection $rankedCandidates, Collection $lines, EnsembleCutoffService $cutoffs): array;

    /**
     * Whether $candidate could ever be accepted by $ensemble under this
     * strategy, regardless of score — the grade gate for
     * GradeSegmentedEnsembles, always true for SequentialEnsembleFill.
     */
    public function canJoin(Candidate $candidate, Ensemble $ensemble): bool;
}
