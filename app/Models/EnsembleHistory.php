<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manually-entered (or auto-snapshotted, see EnsembleHistoryService::
 * recordCurrentSeason()) count of accepted candidates for one Ensemble +
 * VoicePart + season — independent of any Version row, since the prior
 * seasons this exists to record predate this system tracking
 * Candidate::accepted_ensemble_id at all (see Tab Room Module.docx's
 * "History" section). season_year follows the same convention as
 * Version::senior_class_of.
 *
 * @property int $ensemble_id
 * @property int $voice_part_id
 * @property int $season_year
 * @property int $accepted_count
 */
#[Fillable(['ensemble_id', 'voice_part_id', 'season_year', 'accepted_count'])]
class EnsembleHistory extends Model
{
    // "History" pluralizes irregularly under Eloquent's default (would
    // resolve to ensemble_histories) — the migration names the table
    // ensemble_history (singular), so it's set explicitly here.
    protected $table = 'ensemble_history';

    /**
     * @return BelongsTo<Ensemble, $this>
     */
    public function ensemble(): BelongsTo
    {
        return $this->belongsTo(Ensemble::class);
    }

    /**
     * @return BelongsTo<VoicePart, $this>
     */
    public function voicePart(): BelongsTo
    {
        return $this->belongsTo(VoicePart::class);
    }
}
