<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The current Ensemble Participants CSV for one Version × Ensemble, stored
 * on S3 (EnsembleParticipantsCsvService).
 *
 * @property Carbon $generated_at
 */
#[Fillable(['version_id', 'ensemble_id', 's3_key', 'filename', 'row_count', 'generated_at', 'generated_by_user_id'])]
class VersionEnsembleParticipantExport extends Model
{
    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Version, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /** @return BelongsTo<Ensemble, $this> */
    public function ensemble(): BelongsTo
    {
        return $this->belongsTo(Ensemble::class);
    }
}
