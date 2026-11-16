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
 * The single-shared-cutoff Ensemble Cut-off behaviors
 * (PerVoicePartPerEnsemble, AlternatingEnsembleAssignment) share this
 * contract: EnsembleCutoffService::applyCutoff() calls apply() once per
 * eligible Ensemble with the same score, then rejects everyone below it.
 * Each implementation only ever ACCEPTS candidates into $ensemble — it
 * never rejects. The per-Ensemble strategies (SequentialEnsembleFill,
 * GradeSegmentedEnsembles) implement PerEnsembleCutoffStrategy instead.
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
