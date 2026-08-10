<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A frozen, one-row-per-candidate snapshot of a candidate's tallied
 * audition score. Originally scoped as "written once at Version close" —
 * revised once Ensemble Cut-offs (§7.4-§7.5) was built: it's actually
 * written per-candidate at cut-off-application time (EnsembleCutoffService::
 * acceptCandidate()/rejectCandidate()), since that's the moment a
 * candidate's total needs to be frozen for the assignment decision, not
 * Version close. Before a candidate is resolved by a cutoff, the
 * comparable total is computed on demand by AdjudicationService::
 * candidateTotals() (or EnsembleCutoffService::voicePartTotals() for the
 * multi-Room sum), the same live-aggregate pattern already used for
 * candidateStatuses()/candidateTolerances(); this table exists so results
 * PDFs and rehearsal exports (§7.6-§7.7) have a stable number that can't
 * drift if a Score row is touched after the fact. Deliberately excludes the
 * legacy `accepted`/`acceptance_abbr` columns — ensemble-assignment
 * outcome belongs on Candidate's own status enum + accepted_ensemble_id
 * (Candidate already has accepted/not_accepted states), not duplicated
 * here as a second in/out flag (see event-version-orientation.md §5.2,
 * §9).
 *
 * @property int $candidate_id
 * @property int $voice_part_order_by
 * @property int $score_count
 * @property int $total
 */
#[Fillable([
    'candidate_id', 'version_id', 'voice_part_id', 'school_id',
    'voice_part_order_by', 'score_count', 'total',
])]
class AuditionResult extends Model
{
    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * @return BelongsTo<VoicePart, $this>
     */
    public function voicePart(): BelongsTo
    {
        return $this->belongsTo(VoicePart::class);
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
