<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CandidateUploadStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'candidate_id', 'version_upload_file_id', 'uploaded_by_user_id', 'url', 'original_filename',
    'duration_seconds', 'flagged_at', 'flag_reason', 'status', 'uploaded_at', 'decided_at', 'decided_by_user_id',
])]
class CandidateUploadFile extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CandidateUploadStatus::class,
            'uploaded_at' => 'datetime',
            'decided_at' => 'datetime',
            'flagged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<VersionUploadFile, $this>
     */
    public function versionUploadFile(): BelongsTo
    {
        return $this->belongsTo(VersionUploadFile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
