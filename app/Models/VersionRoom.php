<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $order_by
 */
#[Fillable(['version_id', 'name', 'tolerance', 'order_by'])]
class VersionRoom extends Model
{
    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * A Room's scoring rubric is the union of its assigned categories'
     * factors — no per-factor cherry-picking (see event-version-orientation.md §5.9).
     *
     * @return BelongsToMany<ScoreCategory, $this>
     */
    public function scoreCategories(): BelongsToMany
    {
        return $this->belongsToMany(ScoreCategory::class, 'room_score_categories', 'room_id', 'score_category_id')
            ->withTimestamps()
            ->orderBy('order_by');
    }

    /**
     * @return BelongsToMany<VoicePart, $this>
     */
    public function voiceParts(): BelongsToMany
    {
        return $this->belongsToMany(VoicePart::class, 'room_voice_parts', 'room_id', 'voice_part_id')
            ->withTimestamps()
            ->orderBy('sort_order');
    }

    /**
     * @return HasMany<RoomJudge, $this>
     */
    public function roomJudges(): HasMany
    {
        return $this->hasMany(RoomJudge::class, 'room_id');
    }
}
