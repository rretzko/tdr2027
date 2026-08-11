<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PdfExportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The latest "All Ensembles" Combined Audition Scores PDF export state for
 * one (Version, confidential/public) pair — one row per pair, not an
 * append-only log (see the unique(version_id, confidential) index). Backs
 * GenerateCombinedScoresPdfJob: `report_generation` records the
 * TabRoomReportCache generation this PDF was built from, so a later request
 * at the same generation can reuse `s3_key` instead of re-running the job.
 */
#[Fillable(['version_id', 'confidential', 'requested_by_user_id', 'report_generation', 's3_key', 'status', 'failure_reason'])]
class CombinedScoresPdfExport extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidential' => 'boolean',
            'status' => PdfExportStatus::class,
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
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
