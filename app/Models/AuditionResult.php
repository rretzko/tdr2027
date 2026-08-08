<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A frozen, one-row-per-candidate snapshot of a candidate's tallied
 * audition score, written once at Version close — not a live running
 * total. While adjudication is open (and through any Tab Room
 * override), the comparable total is computed on demand by
 * AdjudicationService::candidateTotals(), the same live-aggregate
 * pattern already used for candidateStatuses()/candidateTolerances();
 * this table only exists so cut-offs (§7.4), results PDFs, and
 * rehearsal exports (§7.6-§7.7) have a stable number that can't drift
 * if a Score row is touched after the fact. Deliberately excludes the
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
