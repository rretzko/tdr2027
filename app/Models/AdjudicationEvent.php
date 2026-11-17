<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdjudicationEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only log of what judges do on the Adjudicate page (opening a
 * candidate, playing/pausing/finishing a recording), for adjudication
 * analytics: time on a candidate before saving, whether whole recordings
 * were heard, and the order judges work through their roster.
 */
#[Fillable(['version_id', 'room_judge_id', 'candidate_id', 'recording_id', 'event', 'position_seconds', 'roster_position'])]
class AdjudicationEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AdjudicationEventType::class,
            'created_at' => 'datetime',
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
     * @return BelongsTo<RoomJudge, $this>
     */
    public function roomJudge(): BelongsTo
    {
        return $this->belongsTo(RoomJudge::class);
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<Recording, $this>
     */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(Recording::class);
    }
}
