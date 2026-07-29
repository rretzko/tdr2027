<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $order_by
 */
#[Fillable([
    'event_id', 'version_id', 'score_category_id', 'description', 'abbreviation',
    'best', 'worst', 'interval_by', 'multiplier', 'tolerance', 'order_by',
])]
class ScoreFactor extends Model
{
    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * @return BelongsTo<ScoreCategory, $this>
     */
    public function scoreCategory(): BelongsTo
    {
        return $this->belongsTo(ScoreCategory::class);
    }
}
