<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schema/model only this phase — no live scoring entry UI. Denormalized
 * columns (student_id/school_id/voice_part_id, the *_order_by snapshots)
 * are frozen at scoring time, not live-state duplicates (see
 * event-version-orientation.md §5.9).
 *
 * @property int $score_category_order_by
 * @property int $score_factor_order_by
 * @property int $judge_order_by
 * @property int $voice_part_order_by
 * @property int $score
 */
#[Fillable([
    'version_id', 'candidate_id', 'student_id', 'school_id',
    'score_category_id', 'score_category_order_by',
    'score_factor_id', 'score_factor_order_by',
    'judge_id', 'judge_order_by',
    'voice_part_id', 'voice_part_order_by',
    'score', 'overridden_by_user_id', 'overridden_at',
])]
class Score extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'overridden_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return BelongsTo<ScoreCategory, $this>
     */
    public function scoreCategory(): BelongsTo
    {
        return $this->belongsTo(ScoreCategory::class);
    }

    /**
     * @return BelongsTo<ScoreFactor, $this>
     */
    public function scoreFactor(): BelongsTo
    {
        return $this->belongsTo(ScoreFactor::class);
    }

    /**
     * @return BelongsTo<RoomJudge, $this>
     */
    public function judge(): BelongsTo
    {
        return $this->belongsTo(RoomJudge::class, 'judge_id');
    }

    /**
     * @return BelongsTo<VoicePart, $this>
     */
    public function voicePart(): BelongsTo
    {
        return $this->belongsTo(VoicePart::class);
    }

    /**
     * The Tab Room Manager who entered/changed this score on a judge's
     * behalf via Add/Edit Scores — null for a score the judge entered
     * themselves via Adjudicate.
     *
     * @return BelongsTo<User, $this>
     */
    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by_user_id');
    }
}
