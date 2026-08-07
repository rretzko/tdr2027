<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate-submitted audition recording. `file_type` doubles as the
 * score category name for this data set (e.g. "scales", "solo", "quintet")
 * — the Judging module matches a Recording to a Room's rubric by comparing
 * `file_type` to `score_categories.description`, case-insensitively (see
 * AdjudicationService::recordingsForCandidate()).
 */
#[Fillable(['version_id', 'candidate_id', 'file_type', 'uploaded_by', 'approved_at', 'approved_by', 'url'])]
class Recording extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
