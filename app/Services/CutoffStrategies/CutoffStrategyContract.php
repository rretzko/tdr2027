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
 * The four Ensemble Cut-off assignment behaviors (App\Enums\CutoffStrategy)
 * share this contract. Each implementation only ever ACCEPTS candidates
 * into $ensemble — it never rejects. EnsembleCutoffService::finalizeVoicePart()
 * is the single place that marks everyone still unresolved as not_accepted,
 * once every relevant Ensemble's cutoff has been applied — this lets
 * SequentialEnsembleFill/GradeSegmentedEnsembles apply one Ensemble's cutoff
 * per manager click without a partial mid-sequence candidate being
 * prematurely rejected before the next Ensemble gets its turn.
 */
interface CutoffStrategyContract
{
    /**
     * @param  Collection<int, array{candidate: Candidate, total: int, scoreCount: int}>  $rankedCandidates
     *                                                                                                       Completed candidates for $voicePart, best-to-worst per Version::score_order, still status=Registered
     *                                                                                                       (i.e. not yet resolved by an earlier cutoff click).
     * @param  Collection<int, Ensemble>  $eligibleEnsembles  Every Ensemble that serves $voicePart, in the
     *                                                        Version's ensembleOrder priority — needed by AlternatingEnsembleAssignmentStrategy to compute
     *                                                        each candidate's rotation slot.
     */
    public function apply(
        Version $version,
        VoicePart $voicePart,
        Ensemble $ensemble,
        int $cutoffScore,
        Collection $rankedCandidates,
        Collection $eligibleEnsembles,
        EnsembleCutoffService $cutoffs,
    ): void;
}
