<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One applied cutoff line: the score at or better than which candidates in
 * $voice_part_id are accepted into $ensemble_id, for the per-Ensemble
 * cut-off strategies. See EnsembleCutoffService::applyScoreClick().
 *
 * @property int $voice_part_id
 * @property int $ensemble_id
 * @property int $score
 */
#[Fillable(['version_id', 'voice_part_id', 'ensemble_id', 'score'])]
class VersionEnsembleCutoff extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
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
     * @return BelongsTo<VoicePart, $this>
     */
    public function voicePart(): BelongsTo
    {
        return $this->belongsTo(VoicePart::class);
    }

    /**
     * @return BelongsTo<Ensemble, $this>
     */
    public function ensemble(): BelongsTo
    {
        return $this->belongsTo(Ensemble::class);
    }
}
